<?php

namespace App\Support;

final class BotTexts
{
    public static function welcome(): string
    {
        return 'Здравствуйте! Пришлите PDF-файл — посчитаю страницы и скажу стоимость.';
    }

    public static function onlyPdf(): string
    {
        return 'Принимаю только PDF. В Word: «Файл → Сохранить как → PDF».';
    }

    public static function noFileLink(): string
    {
        return 'Не получил ссылку на файл, попробуйте отправить ещё раз.';
    }

    public static function tooBig(int $megabytes): string
    {
        return "Файл больше {$megabytes} МБ — такой не обработаю.";
    }

    public static function pages(int $count): string
    {
        return "В файле {$count} стр.";
    }

    public static function countFailed(string $reason): string
    {
        return 'Не смог посчитать страницы: '.$reason;
    }
}