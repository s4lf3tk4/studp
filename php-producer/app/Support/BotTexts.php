<?php

namespace App\Support;

final class BotTexts
{
    /** Что делает бот — приходит после нажатия «Начать» */
    public static function welcome(): string
    {
        return "Я считаю страницы в PDF-файлах и сразу говорю, сколько будет стоить обработка. \n".
                "Сейчас выберите корпус в котором хотите распечатать и получить файл, \n".
                "Отправьте файл в чат \n".
                "После оплаты ожидайте увевдомления в этом чате о статусе 'готово' от типографии.";
    }

    /** Инструкция после нажатия «Отправить файл» */
    public static function fileInstructions(): string
    {
        return "Пришлите PDF-файл документом — не картинкой и не ссылкой.\n"
            ."Если файл в Word: «Файл → Сохранить как → PDF».\n"
            .'После отправки выберите доступный корпус, а я посчитаю страницы и назову стоимость.';
    }
    
    public static function campusRequired(): string
    {
        return 'Сначала выберите корпус, в котором нужно распечатать:';
    }
    
    public static function campusChosen(string $campusNumber, ?string $schedule = null): string
    {
        $text = "Вы выбрали корпус {$campusNumber}.\n";

        if ($schedule !== null) {
            $text .= "\n{$schedule}\n";
        }

        return $text."\nОтправьте файл в формате PDF\n";
    }
        /** @param int|null $pricePerPageKopecks цена за страницу корпуса: null — показываем только страницы */
    public static function pages(int $count, ?int $pricePerPageKopecks = null): string
    {
        $text = "В файле {$count} стр.";

        if ($pricePerPageKopecks === null) {
            return $text;
        }

        return $text.' Стоимость: '.Money::rub($count * $pricePerPageKopecks)
            .' ('.Money::rub($pricePerPageKopecks).' за страницу).';
    }   

    public static function counting(): string
    {
        return 'Файл получил. Считаю страницы и стоимость — ответ пришлю в этот чат.';
    }
    
    public static function campusInactive(string $campusNumber): string
    {
        return "{$campusNumber} сейчас не принимает заказы — выберите другой корпус.";
    }
    
    public static function orderNotFound(): string
    {
        return 'Не нашёл ваш заказ — пришлите PDF ещё раз.';
    }

    public static function orderFailed(): string
    {
        return 'Не смог создать заказ, попробуйте ещё раз.';
    }

    public static function fileCanceled(): string
    {
        return "Файл отменён и удалён.\nВыберите корпус и пришлите другой PDF.";
    }

    public static function orderCannotCancel(int $id): string
    {
        return "Заказ №{$id} уже создан — отменить его кнопкой нельзя.";
    }

    public static function orderConfirmed(int $id, ?string $paymentUrl = null): string
    {
        $text = "Заказ №{$id} принят, ждём оплату.";

        return $paymentUrl !== null ? $text."\nОплатить: {$paymentUrl}" : $text;
    }

    public static function alreadyConfirmed(int $id, ?string $paymentUrl = null): string
    {
        $text = "Заказ №{$id} уже создан.";

        return $paymentUrl !== null ? $text."\nОплатить: {$paymentUrl}" : $text;
    }
    

    public static function cancelBtn(): string
    {
        return "Отмена выбора\n";
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


    public static function countFailed(string $reason): string
    {
        return 'Не смог посчитать страницы: '.$reason;
    }
}