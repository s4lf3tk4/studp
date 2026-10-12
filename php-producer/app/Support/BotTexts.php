<?php

namespace App\Support;

final class BotTexts
{
    /** Что делает бот — приходит после нажатия «Начать» */
    public static function welcome(): string
    {
        return 'Я считаю страницы в PDF-файлах и сразу говорю, сколько будет стоить обработка.';
    }

    /** Инструкция после нажатия «Отправить файл» */
    public static function fileInstructions(): string
    {
        return "Пришлите PDF-файл документом — не картинкой и не ссылкой.\n"
            ."Если файл в Word: «Файл → Сохранить как → PDF».\n"
            .'После отправки я посчитаю страницы и назову стоимость.';
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