<?php

namespace App\Services;

use MJohann\Packlib\PaginatedFileReader;   // ← сверить с vendor/mjohann/paginated-file-reader/composer.json
use RuntimeException;

final class DocumentPageCounter
{
    private const MAX_BYTES = 30 * 1024 * 1024;
    private const MAX_PAGES = 1000;
    private const SUPPORTED = ['pdf', 'docx'];

    public function count(string $absolutePath): int
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Файл не найден');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED, true)) {
            throw new RuntimeException('Поддерживаются только PDF и DOCX');
        }

        if (filesize($absolutePath) > self::MAX_BYTES) {
            throw new RuntimeException('Файл больше 30 МБ');
        }

        $pages = (int) (new PaginatedFileReader($absolutePath))->getTotalPages();

        if ($pages < 1 || $pages > self::MAX_PAGES) {
            throw new RuntimeException("Неправдоподобное число страниц: {$pages}");
        }

        return $pages;
    }
}