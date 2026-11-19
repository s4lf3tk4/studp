<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Собирает PDF из JPEG: одна картинка — одна страница A4.
 * PDF пишется сразу в файл потоком: в памяти держится только голова файла
 * (размеры и EXIF) и буфер копирования, поэтому 50 страниц не съедают память.
 */
final class PhotoPdf
{
    /** A4 в pt */
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** Поля по 5 мм */
    private const MARGIN = 14.17;

    /** Сколько байт читаем с начала файла для SOF и EXIF */
    private const HEAD_BYTES = 131072;

    /**
     * @param  array<int, string>  $imagePaths  JPEG на диске, порядок = порядок страниц
     * @return int размер готового PDF в байтах
     */
    public function build(array $imagePaths, string $targetPath): int
    {
        if ($imagePaths === []) {
            throw new RuntimeException('Нет изображений для PDF');
        }

        $out = @fopen($targetPath, 'wb');

        if ($out === false) {
            throw new RuntimeException('Не открыл файл для записи: '.basename($targetPath));
        }

        try {
            $this->write($out, "%PDF-1.4\n");

            $count = count($imagePaths);
            $offsets = [0];

            // 1 — каталог, 2 — дерево страниц; дальше на каждое фото по три объекта
            $offsets[1] = (int) ftell($out);
            $this->write($out, "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

            $kids = [];

            for ($index = 0; $index < $count; $index++) {
                $kids[] = (5 + $index * 3).' 0 R';
            }

            $offsets[2] = (int) ftell($out);
            $this->write(
                $out,
                "2 0 obj\n<< /Type /Pages /Kids [".implode(' ', $kids)."] /Count {$count} >>\nendobj\n",
            );

            foreach ($imagePaths as $index => $path) {
                $imageNumber = 3 + $index * 3;
                $contentNumber = 4 + $index * 3;
                $pageNumber = 5 + $index * 3;

                $in = @fopen($path, 'rb');

                if ($in === false) {
                    throw new RuntimeException('Не читается изображение: '.basename($path));
                }

                try {
                    $head = (string) fread($in, self::HEAD_BYTES);
                    [$width, $height] = $this->jpegSize($head);
                    $rotate = $this->exifOrientation($head);

                    $size = (int) filesize($path);

                    if ($size <= 0) {
                        throw new RuntimeException('Пустой файл: '.basename($path));
                    }

                    $offsets[$imageNumber] = (int) ftell($out);
                    $this->write($out, $imageNumber.' 0 obj'."\n"
                        .'<< /Type /XObject /Subtype /Image'
                        .' /Width '.$width.' /Height '.$height
                        .' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode'
                        .' /Length '.$size.' >>'."\nstream\n");

                    // Копируем байты как есть: без декодирования и без памяти на всё изображение
                    rewind($in);
                    $copied = stream_copy_to_stream($in, $out, $size);

                    if ($copied === false || $copied !== $size) {
                        throw new RuntimeException('Не скопировал изображение: '.basename($path));
                    }

                    $this->write($out, "\nendstream\nendobj\n");

                    $stream = "q\n".$this->placement($width, $height, $rotate)." cm\n/Im0 Do\nQ\n";

                    $offsets[$contentNumber] = (int) ftell($out);
                    $this->write($out, $contentNumber.' 0 obj'."\n"
                        .'<< /Length '.strlen($stream).' >>'."\nstream\n{$stream}endstream\nendobj\n");

                    $offsets[$pageNumber] = (int) ftell($out);
                    $this->write($out, $pageNumber.' 0 obj'."\n"
                        .'<< /Type /Page /Parent 2 0 R'
                        .' /MediaBox [0 0 '.$this->num(self::PAGE_WIDTH).' '.$this->num(self::PAGE_HEIGHT).']'
                        .' /Resources << /XObject << /Im0 '.$imageNumber.' 0 R >> >>'
                        .' /Contents '.$contentNumber.' 0 R >>'."\nendobj\n");
                } finally {
                    fclose($in);
                }
            }

            $objectCount = 2 + $count * 3;
            $xref = (int) ftell($out);
            $this->write($out, "xref\n0 ".($objectCount + 1)."\n0000000000 65535 f \n");

            for ($number = 1; $number <= $objectCount; $number++) {
                $this->write($out, sprintf("%010d 00000 n \n", $offsets[$number] ?? 0));
            }

            $this->write($out, 'trailer'."\n<< /Size ".($objectCount + 1).' /Root 1 0 R >>'
                ."\nstartxref\n{$xref}\n%%EOF\n");
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($targetPath);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Не собрал PDF: '.$e->getMessage());
        }

        fclose($out);

        return (int) filesize($targetPath);
    }

    /** Матрица размещения картинки на странице с учётом EXIF-поворота */
    private function placement(int $pixelWidth, int $pixelHeight, int $rotate): string
    {
        $quarterTurn = in_array($rotate, [5, 6, 7, 8], true);

        $sourceWidth = $quarterTurn ? (float) $pixelHeight : (float) $pixelWidth;
        $sourceHeight = $quarterTurn ? (float) $pixelWidth : (float) $pixelHeight;

        $maxWidth = self::PAGE_WIDTH - 2 * self::MARGIN;
        $maxHeight = self::PAGE_HEIGHT - 2 * self::MARGIN;

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);

        $drawWidth = $sourceWidth * $scale;
        $drawHeight = $sourceHeight * $scale;

        $x = (self::PAGE_WIDTH - $drawWidth) / 2;
        $y = (self::PAGE_HEIGHT - $drawHeight) / 2;

        $matrix = match ($rotate) {
            2 => [-$drawWidth, 0.0, 0.0, $drawHeight, $x + $drawWidth, $y],
            3 => [-$drawWidth, 0.0, 0.0, -$drawHeight, $x + $drawWidth, $y + $drawHeight],
            4 => [$drawWidth, 0.0, 0.0, -$drawHeight, $x, $y + $drawHeight],
            5 => [0.0, $drawHeight, $drawWidth, 0.0, $x, $y],
            6 => [0.0, -$drawHeight, $drawWidth, 0.0, $x, $y + $drawHeight],
            7 => [0.0, -$drawHeight, -$drawWidth, 0.0, $x + $drawWidth, $y + $drawHeight],
            8 => [0.0, $drawHeight, -$drawWidth, 0.0, $x + $drawWidth, $y],
            default => [$drawWidth, 0.0, 0.0, $drawHeight, $x, $y],
        };

        return implode(' ', array_map(fn (float $value): string => $this->num($value), $matrix));
    }

    /** @return array{0: int, 1: int} ширина и высота в пикселях */
    private function jpegSize(string $head): array
    {
        $length = strlen($head);

        if ($length < 4 || substr($head, 0, 2) !== "\xFF\xD8") {
            throw new RuntimeException('Это не JPEG');
        }

        $offset = 2;

        while ($offset + 9 < $length) {
            if ($head[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($head[$offset + 1]);
            $offset += 2;

            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segment = unpack('n', substr($head, $offset, 2))[1];

            // SOF0…SOF15, кроме DHT (C4), JPG (C8) и DAC (CC)
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                if ($offset + 7 > $length) {
                    break;
                }

                $height = unpack('n', substr($head, $offset + 3, 2))[1];
                $width = unpack('n', substr($head, $offset + 5, 2))[1];

                if ($width > 0 && $height > 0) {
                    return [$width, $height];
                }

                break;
            }

            $offset += $segment;
        }

        throw new RuntimeException('Не нашёл размеры JPEG');
    }

    /** Ориентация из EXIF: 1 — как есть, 6 — поворот на 90° и т.д. */
    private function exifOrientation(string $head): int
    {
        $position = strpos($head, "Exif\x00\x00");

        if ($position === false) {
            return 1;
        }

        $tiff = $position + 6;

        if ($tiff + 8 > strlen($head)) {
            return 1;
        }

        $order = substr($head, $tiff, 2);
        $little = $order === 'II';

        if (! $little && $order !== 'MM') {
            return 1;
        }

        $ifdOffset = $this->u32(substr($head, $tiff + 4, 4), $little);
        $ifd = $tiff + $ifdOffset;

        if ($ifd + 2 > strlen($head)) {
            return 1;
        }

        $entries = $this->u16(substr($head, $ifd, 2), $little);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($head)) {
                break;
            }

            if ($this->u16(substr($head, $entry, 2), $little) !== 0x0112) {
                continue;
            }

            $value = $this->u16(substr($head, $entry + 8, 2), $little);

            return $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }

    private function u16(string $bytes, bool $little): int
    {
        return (int) unpack($little ? 'v' : 'n', $bytes)[1];
    }

    private function u32(string $bytes, bool $little): int
    {
        return (int) unpack($little ? 'V' : 'N', $bytes)[1];
    }

    /** @param  resource  $handle */
    private function write($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) === false) {
            throw new RuntimeException('Не записал PDF: диск переполнен или файл закрыт');
        }
    }

    /** Число для PDF: точка, без экспоненты и хвостовых нулей */
    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}