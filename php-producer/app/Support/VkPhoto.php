<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/** Выбор самой крупной версии фото из объекта ВК */
final class VkPhoto
{
    public static function largestUrl(mixed $photo): ?string
    {
        if (! is_array($photo)) {
            return null;
        }

        $sizes = $photo['sizes'] ?? [];

        // Иногда sizes пуст, а оригинал всё равно есть
        if ($sizes === [] && is_array($photo['orig_photo'] ?? null)) {
            $sizes = [$photo['orig_photo']];
        }

        $best = null;
        $bestArea = 0;
        $lastUrl = null;

        foreach ($sizes as $size) {
            $url = (string) ($size['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $lastUrl = $url;

            $area = (int) ($size['width'] ?? 0) * (int) ($size['height'] ?? 0);

            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $url;
            }
        }

        if ($best === null) {
            Log::channel('stderr')->warning('Фото без размеров, беру последнюю версию', [
                'photo_id' => $photo['id'] ?? null,
                'sizes' => count($sizes),
            ]);
        }

        return $best
            ?? $lastUrl
            ?? (is_string($photo['orig_photo']['url'] ?? null) ? $photo['orig_photo']['url'] : null);
    }
}