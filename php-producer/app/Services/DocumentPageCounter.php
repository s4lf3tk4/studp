<?php

namespace App\Services;

use App\Support\PaperFormat;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Считает страницы PDF через pdfinfo (poppler-utils) и определяет формат каждой страницы.
 * DOCX разбирается без рендеринга: страницы из docProps/app.xml, форматы из <w:pgSz>.
 */
final class DocumentPageCounter
{
    public const MAX_PAGES = 2000;

    /** Маркер, которым Word отмечает уже отрендеренные концы страниц */
    private const DOCX_PAGE_BREAK = 'lastRenderedPageBreak';

    /** Явный разрыв страницы, вставленный руками */
    private const DOCX_HARD_BREAK = 'w:type="page"';

    /**
     * Число страниц DOCX без рендеринга.
     * Порядок: docProps/app.xml → маркеры концов страниц → явные разрывы.
     */
    public function countFromDocx(string $path): int
    {
        [$app, $body] = $this->docxParts($path);

        if (preg_match('/<Pages>(\d+)<\/Pages>/', $app, $m) === 1 && (int) $m[1] > 0) {
            return $this->assertPages((int) $m[1]);
        }

        $breaks = substr_count($body, self::DOCX_PAGE_BREAK);

        if ($breaks > 0) {
            return $this->assertPages($breaks + 1);
        }

        $explicit = substr_count($body, self::DOCX_HARD_BREAK);

        if ($explicit > 0) {
            return $this->assertPages($explicit + 1);
        }

        throw new RuntimeException('В документе нет данных о страницах — сохраните его в PDF');
    }

    /**
     * Сколько страниц какого формата в DOCX. Размер листа лежит в
     * <w:sectPr><w:pgSz w:w="…" w:h="…"> в двадцатых долях пункта; у каждого
     * раздела свой sectPr, поэтому документ может быть смешанным: A4 плюс A3.
     *
     * @return array<string, int> формат => страниц, в порядке A4…A0
     */
    public function formatsFromDocx(string $path, int $pages): array
    {
        [, $body] = $this->docxParts($path);

        $sections = $this->docxSections($body);

        if ($sections === []) {
            Log::warning('В DOCX не нашёл размеров страниц — считаю весь файл A4', [
                'file' => basename($path),
                'pages' => $pages,
            ]);

            return [PaperFormat::A4 => max(1, $pages)];
        }

        $counts = [];

        foreach ($sections as $section) {
            $counts[$section['format']] = ($counts[$section['format']] ?? 0) + $section['pages'];
        }

        // Сумма по секциям может не совпасть с числом страниц из app.xml — выравниваем
        if (array_sum($counts) !== $pages) {
            Log::warning('Страницы DOCX по секциям не сошлись с общим числом', [
                'file' => basename($path),
                'sections' => array_sum($counts),
                'pages' => $pages,
            ]);

            $counts = $this->balanceCounts($counts, $pages);
        }

        return PaperFormat::sort($counts);
    }

    /** Локальный файл: pdfinfo читает его сам, без cat и без pipe */
    public function countFromFile(string $path): int
    {
        $output = $this->run('pdfinfo '.escapeshellarg($path).' 2>&1');

        foreach ($output as $line) {
            if (preg_match('/^Pages:\s+(\d+)/', $line, $m) === 1) {
                return $this->assertPages((int) $m[1]);
            }
        }

        throw new RuntimeException('Не удалось определить число страниц в PDF');
    }

    /**
     * Формат каждой страницы PDF: pdfinfo с диапазоном печатает
     * «Page    3 size: 595.28 x 841.89 pts (A4)» для каждой страницы.
     *
     * @return array<string, int> формат => страниц, в порядке A4…A0
     */
    public function formatsFromFile(string $path, int $pages): array
    {
        $last = max(1, min($pages, self::MAX_PAGES));

        $output = $this->run('pdfinfo -f 1 -l '.$last.' '.escapeshellarg($path).' 2>&1');

        $counts = [];
        $found = 0;

        foreach ($output as $line) {
            if (preg_match('/^Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/i', $line, $m) !== 1) {
                continue;
            }

            $format = PaperFormat::fromPoints((float) $m[1], (float) $m[2]);
            $counts[$format] = ($counts[$format] ?? 0) + 1;
            $found++;
        }

        // pdfinfo старой версии размеров постранично не отдаёт — считаем весь файл A4
        if ($found === 0) {
            Log::warning('pdfinfo не отдал размеры страниц — весь файл считаю A4', [
                'file' => basename($path),
                'pages' => $pages,
            ]);

            return [PaperFormat::A4 => max(1, $pages)];
        }

        // Размер нашёлся не для всех страниц: остаток считаем A4, чтобы сумма сходилась
        if ($found < $pages) {
            Log::warning('Размеры страниц нашлись не для всех страниц', [
                'file' => basename($path),
                'pages' => $pages,
                'found' => $found,
            ]);

            $counts[PaperFormat::A4] = ($counts[PaperFormat::A4] ?? 0) + ($pages - $found);
        }

        return PaperFormat::sort($counts);
    }

    /**
     * Страницы по секциям документа.
     * Каждый <w:sectPr> закрывает свою секцию; непрерывный раздел новую страницу не открывает.
     *
     * @return array<int, array{format: string, pages: int}>
     */
    private function docxSections(string $body): array
    {
        $marker = substr_count($body, self::DOCX_PAGE_BREAK) > 0
            ? self::DOCX_PAGE_BREAK
            : self::DOCX_HARD_BREAK;

        if (preg_match_all('/<w:sectPr\b.*?<\/w:sectPr>/s', $body, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $sections = [];
        $consumed = 0;

        foreach ($matches[0] as [$xml, $start]) {
            // Всё, что стояло до этого sectPr, относится к закрываемой секции
            $chunk = substr($body, $consumed, $start - $consumed);
            $consumed = $start + strlen($xml);

            $continuous = str_contains($xml, 'w:val="continuous"');
            $pages = substr_count($chunk, $marker) + ($continuous ? 0 : 1);

            $sections[] = [
                'format' => $this->docxSectionFormat($xml) ?? PaperFormat::A4,
                'pages' => max(1, $pages),
            ];
        }

        return $sections;
    }

    /** Формат листа из <w:pgSz w:w="…" w:h="…"/>; порядок атрибутов произвольный */
    private function docxSectionFormat(string $sectionXml): ?string
    {
        if (preg_match('/<w:pgSz\b[^>]*\/?>/', $sectionXml, $tag) !== 1) {
            return null;
        }

        $width = $this->docxAttribute($tag[0], 'w:w');
        $height = $this->docxAttribute($tag[0], 'w:h');

        if ($width === null || $height === null) {
            return null;
        }

        return PaperFormat::fromTwips($width, $height);
    }

    /** Числовой атрибут тега: размеры в DOCX заданы в twips */
    private function docxAttribute(string $tag, string $name): ?float
    {
        $pattern = '/'.preg_quote($name, '/').'="([\d.]+)"/';

        return preg_match($pattern, $tag, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * Подогнать сумму страниц по секциям под общее число страниц Word.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function balanceCounts(array $counts, int $pages): array
    {
        $diff = $pages - array_sum($counts);

        if ($diff === 0 || $counts === []) {
            return $counts;
        }

        // Правим самый крупный формат: он вероятнее всего «съел» расхождение
        arsort($counts);
        $format = (string) array_key_first($counts);
        $counts[$format] = max(0, $counts[$format] + $diff);

        return array_filter($counts, fn (int $count): bool => $count > 0);
    }

    /** @return array{0: string, 1: string} [docProps/app.xml, word/document.xml] */
    private function docxParts(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть DOCX как архив');
        }

        $app = (string) $zip->getFromName('docProps/app.xml');
        $body = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        if ($body === '') {
            throw new RuntimeException('В файле нет word/document.xml — это не DOCX');
        }

        return [$app, $body];
    }

    private function assertPages(int $pages): int
    {
        if ($pages < 1 || $pages > self::MAX_PAGES) {
            throw new RuntimeException("Неправдоподобное число страниц: {$pages}");
        }

        return $pages;
    }
    
    /**
     * Формат каждой страницы PDF: номер страницы => формат.
     * Нумерация с 1, пропуски заполняются A4.
     *
     * @return array<int, string>
     */
    public function pageFormats(string $path, int $pages): array
    {
        $last = max(1, min($pages, self::MAX_PAGES));

        $output = $this->run('pdfinfo -f 1 -l '.$last.' '.escapeshellarg($path).' 2>&1');

        $found = [];

        foreach ($output as $line) {
            if (preg_match('/^Page\s+(\d+)\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/i', $line, $m) !== 1) {
                continue;
            }

            $found[(int) $m[1]] = PaperFormat::fromPoints((float) $m[2], (float) $m[3]);
        }

        $formats = [];

        for ($page = 1; $page <= $pages; $page++) {
            $formats[$page] = $found[$page] ?? PaperFormat::A4;
        }

        return $formats;
    }

        /**
     * Формат каждой страницы DOCX по секциям: номер страницы => формат.
     * Секции идут в порядке документа, каждая занимает свои страницы.
     *
     * @return array<int, string>
     */
    /**
     * Формат каждой страницы DOCX по секциям: номер страницы => формат.
     * Если секционный счётчик недосчитал страницы, добиваем форматом последней секции,
     * а композицию сверяем с formatsFromDocx() — по ней уже названа цена.
     *
     * @return array<int, string>
     */
    public function docxPageFormats(string $path, int $pages): array
    {
        [, $body] = $this->docxParts($path);

        $list = [];

        foreach ($this->docxSections($body) as $section) {
            for ($i = 0; $i < $section['pages']; $i++) {
                $list[] = $section['format'];
            }
        }

        if ($list === []) {
            $list = array_fill(0, $pages, PaperFormat::A4);
        }

        $list = array_slice(array_values($list), 0, $pages);

        // Недосчитанные страницы продолжают последнюю секцию, а не превращаются в A4
        while (count($list) < $pages) {
            $list[] = $list[count($list) - 1];
        }

        // Композиция форматов должна совпадать с той, по которой посчитана первая цена
        $counts = $this->formatsFromDocx($path, $pages);
        $actual = array_count_values($list);

        ksort($actual);
        ksort($counts);

        if ($actual !== $counts) {
            \Illuminate\Support\Facades\Log::channel('stderr')->warning('Форматы DOCX по страницам не совпали со счётчиками', [
                'file' => basename($path),
                'pages' => $pages,
                'by_pages' => $actual,
                'by_counts' => $counts,
            ]);

            $list = [];

            foreach ($counts as $format => $count) {
                for ($i = 0; $i < (int) $count; $i++) {
                    $list[] = (string) $format;
                }
            }
        }

        $formats = [];

        foreach ($list as $index => $format) {
            $formats[$index + 1] = $format;
        }

        return $formats;
    }

    /** @return array<int, string> */
    private function run(string $shellCommand): array
    {
        $output = [];
        $code = 0;

        exec($shellCommand, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('pdfinfo: '.trim(implode(' ', array_slice($output, 0, 2))));
        }

        return $output;
    }
}