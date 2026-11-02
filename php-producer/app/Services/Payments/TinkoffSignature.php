<?php

namespace App\Services\Payments;

/** Подпись запроса Т-Банк: SHA-256 от значений корневых параметров + Password */
final class TinkoffSignature
{
    public static function make(array $params, string $password): string
    {
        $data = ['Password' => $password];

        foreach ($params as $key => $value) {
            if ($key === 'Token' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $data[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        ksort($data, SORT_STRING);

        return hash('sha256', implode('', $data));
    }
}