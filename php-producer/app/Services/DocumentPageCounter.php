<?php

namespace App\Services;

use RuntimeException;

/**
 * Считает страницы PDF через pdfinfo (poppler-utils).
 * countFromFile — локальный файл, countFromCommand — PDF, напечатанный в stdout (например, curl).
 */
final class DocumentPageCounter
{
    public const MAX_PAGES = 2000;

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