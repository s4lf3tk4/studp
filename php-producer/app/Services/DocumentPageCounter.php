<?php

namespace App\Services;

use RuntimeException;

/**
 * Считает страницы PDF через pdfinfo (poppler-utils).
 * Документ читается потоком из stdin — временный файл не создаётся.
 */
final class DocumentPageCounter
{
    public const MAX_PAGES = 2000;

    /** @param string $command команда, печатающая PDF в stdout (например curl ...) */
    public function countFromCommand(string $command): int
    {
        $output = [];
        $code = 0;

        exec($command.' | pdfinfo - 2>&1', $output, $code);

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