<?php

namespace App\Services;

use RuntimeException;
use ZipArchive; 
/**
 * Считает страницы PDF через pdfinfo (poppler-utils).
 * countFromFile — локальный файл, countFromCommand — PDF, напечатанный в stdout (например, curl).
 */
final class DocumentPageCounter
{
    public const MAX_PAGES = 2000;

    /**
     * Число страниц DOCX без рендеринга: берём данные, которые записал Word.
     * Порядок: docProps/app.xml → маркеры концов страниц → явные разрывы.
     */
    public function countFromDocx(string $path): int
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

        if (preg_match('/<Pages>(\d+)<\/Pages>/', $app, $m) === 1 && (int) $m[1] > 0) {
            return $this->assertPages((int) $m[1]);
        }

        $breaks = substr_count($body, 'lastRenderedPageBreak');

        if ($breaks > 0) {
            return $this->assertPages($breaks + 1);
        }

        $explicit = substr_count($body, 'w:type="page"');

        if ($explicit > 0) {
            return $this->assertPages($explicit + 1);
        }

        throw new RuntimeException('В документе нет данных о страницах — сохраните его в PDF');
    }

    private function assertPages(int $pages): int
    {
        if ($pages < 1 || $pages > self::MAX_PAGES) {
            throw new RuntimeException("Неправдоподобное число страниц: {$pages}");
        }

        return $pages;
    }

    /** Локальный файл: pdfinfo читает его сам, без cat и без pipe */
    public function countFromFile(string $path): int
    {
        return $this->parse('pdfinfo '.escapeshellarg($path).' 2>&1');
    }


    private function parse(string $shellCommand): int
    {
        $output = [];
        $code = 0;

        exec($shellCommand, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('pdfinfo: '.trim(implode(' ', array_slice($output, 0, 2))));
        }

        foreach ($output as $line) {
            if (preg_match('/^Pages:\s+(\d+)/', $line, $m) === 1) {
                $pages = (int) $m[1];

                if ($pages < 1 || $pages > self::MAX_PAGES) {
                    throw new RuntimeException("Неправдоподобное число страниц: {$pages}");
                }

                return $pages;
            }
        }

        throw new RuntimeException('Не удалось определить число страниц в PDF');
    }
}