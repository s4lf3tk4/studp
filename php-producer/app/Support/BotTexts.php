<?php

namespace App\Support;

final class BotTexts
{
    /** Что делает бот — приходит после нажатия «Начать» */
    public static function welcome(): string
    {
        return "Я считаю страницы в PDF и DOCX и сразу говорю, сколько будет стоить печать. \n".
                "Сейчас выберите корпус в котором хотите распечатать и получить файл, \n".
                "Отправьте файл в чат \n".
                "После оплаты ожидайте увевдомления в этом чате о статусе 'готово' от типографии.";
    }

    /** Инструкция после нажатия «Отправить файл» */
    public static function fileInstructions(): string
    {
        return "Пришлите PDF или DOCX документом — не картинкой и не ссылкой.\n"
            ."Если файл в старом Word (.doc): «Файл → Сохранить как → PDF».\n"
            .'После отправки выберите корпус, а я посчитаю страницы, назову цену и спрошу про ЧБ или цвет.';
    }

    public static function campusRequired(): string
    {
        return 'Сначала выберите корпус, в котором нужно распечатать:';
    }

    /** Не тот формат: подсказываем, как получить PDF */
    public static function wrongFormat(): string
    {
        return "Принимаю PDF или DOCX.\n\n"
            .'Старый формат .doc сохраните как PDF:'."\n"
            .'Word → «Файл» → «Сохранить как» → тип «PDF»'."\n\n"
            .'На телефоне: Word → «Поделиться» → «Экспорт в PDF».';
    }


    /** @param int|null $queued сколько оплаченных документов уже ждёт печати в этом корпусе */
    public static function campusChosen(
        string $campusTitle,
        ?string $schedule = null,
        ?int $queued = null,
    ): string {
        $text = "Вы выбрали: {$campusTitle}.\n";

        if ($schedule !== null) {
            $text .= "\n{$schedule}\n";
        }

        if ($queued !== null) {
            $text .= "\n".self::queueLoad($queued);
        }

        return $text."\nОтправьте PDF или DOCX — потом спрошу, печатать ЧБ или цветным.\n";
    }

        /** Вопрос о принтере: форматы страниц и итог в каждом доступном режиме */
    public static function modeQuestion(
        array $formats,
        array $totals,
        bool $fromDocx = false,
    ): string {
        $text = self::formatLines($formats)."\n\n";

        foreach ($totals as $mode => $kopecks) {
            $label = PrintMode::label((string) $mode) ?? (string) $mode;
            $text .= "{$label} — ".Money::rub((int) $kopecks)."\n";
        }

        if ($fromDocx) {
            $text .= "\n".self::docxNote()."\n";
        }

        return $text."\nВыберите, на каком принтере печатать:";
    }

    /** Режим выбран: страницы по форматам, цена формата и итог */
    public static function modeConfirmed(
        string $modeLabel,
        array $formats,
        array $prices,
        int $totalKopecks,
        bool $fromDocx = false,
    ): string {
        $text = "Печать: {$modeLabel}";

        foreach ($formats as $format => $pages) {
            $price = (int) ($prices[$format] ?? 0);
            $text .= "\n{$format} — {$pages} стр. × ".Money::rub($price)
                .' = '.Money::rub($price * (int) $pages);
        }

        $text .= "\nИтого: ".Money::rub($totalKopecks);

        if ($fromDocx) {
            $text .= "\n\n".self::docxNote();
        }

        return $text."\n\nНажмите «Подтвердить», чтобы получить ссылку на оплату.";
    }

    /** Только страницы и форматы: цены в корпусе не заданы */
    public static function pagesOnly(array $formats, bool $fromDocx = false): string
    {
        $text = self::formatLines($formats);

        if ($fromDocx) {
            $text .= "\n\n".self::docxNote();
        }

        return $text;
    }

    /** Строка форматов для админки и сообщений: «A4 ×10, A3 ×2» */
    public static function formatsLine(array $formats): string
    {
        if ($formats === []) {
            return '';
        }

        $parts = [];

        foreach ($formats as $format => $pages) {
            $parts[] = $format.' ×'.$pages;
        }

        return implode(', ', $parts);
    }

    /** «В файле 12 стр.:» + строка на каждый формат */
    private static function formatLines(array $formats): string
    {
        $total = array_sum($formats);
        $lines = ["В файле {$total} стр.:"];

        foreach ($formats as $format => $pages) {
            $lines[] = "{$format} — {$pages} стр.";
        }

        return implode("\n", $lines);
    }

    /** Режим в корпусе не настроен: цены 0 в админке */
    public static function modeUnavailable(string $modeLabel, array $missing = []): string
    {
        $text = "Режим «{$modeLabel}» в этом корпусе сейчас недоступен";

        if ($missing !== []) {
            $text .= ' для формата '.implode(', ', $missing);
        }

        return $text.' — выберите другой.';
    }

    /** Загруженность корпуса: сколько файлов стоит в очереди на печать */
    private static function queueLoad(int $queued): string
    {
        if ($queued === 0) {
            return 'Очередь на печать: свободно, напечатаем сразу.';
        }

        $word = self::plural($queued, 'документ', 'документа', 'документов');

        return "В очереди на печать: {$queued} {$word}.";
    }

    private static function plural(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }

    /** Неизвестный текст: подсказываем, что делать дальше */
    public static function unknownCommand(): string
    {
        return "Не понял сообщение.\n"
            .'Выберите корпус кнопкой ниже и пришлите PDF — посчитаю страницы и назову цену.';
    }

    /** Платёжный сервис не ответил — ссылку создать не удалось */
    public static function paymentUnavailable(): string
    {
        return "Не удалось сформировать ссылку на оплату: платёжный сервис не ответил.\n\n"
            .'Заказ сохранён. Нажмите «Подтвердить» ещё раз через минуту.';
    }

    public static function orderCanceled(int $id): string
    {
        return "Заказ №{$id} отменён, файл удалён.\n Можно прислать другой PDF.";
    }

    public static function orderCancelForbidden(int $id): string
    {
        return "Заказ №{$id} уже оплачен — отменить его кнопкой нельзя.\n"
            .'Напишите в типографию, если нужно оформить возврат.';
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

    /** Приходит сразу после нажатия «Подтвердить» */
    public static function paymentLinkCreating(): string
    {
        return 'Формирую ссылку на оплату…';
    }

        public static function orderConfirmed(
        int $id,
        string $fileName,
        int $pages,
        int $amountKopecks,
        ?string $paymentUrl = null,
        ?string $modeLabel = null,
        ?string $formats = null,
    ): string {
        $text = "Заказ №{$id}\n"
            ."Файл: {$fileName}\n"
            ."Страниц: {$pages}\n";

        if ($formats !== null && $formats !== '') {
            $text .= "Форматы: {$formats}\n";
        }

        if ($modeLabel !== null) {
            $text .= "Печать: {$modeLabel}\n";
        }

        $text .= 'К оплате: '.Money::rub($amountKopecks);

        return $paymentUrl !== null ? $text."\n\nОплатить: {$paymentUrl}" : $text;
    }

    public static function orderPrinted(
        int $id,
        string $fileName,
        int $pages,
        string $campusTitle,
        ?string $address = null,
        ?string $room = null,
        ?string $modeLabel = null,
        ?string $formats = null,
    ): string {
        $text = "Заказ №{$id} готов и напечатан.\n\n"
            ."Файл: {$fileName}\n"
            ."Страниц: {$pages}\n";

        if ($formats !== null && $formats !== '') {
            $text .= "Форматы: {$formats}\n";
        }

        if ($modeLabel !== null) {
            $text .= "Печать: {$modeLabel}\n";
        }

        $text .= "Корпус: {$campusTitle}";

        if ($address !== null && $address !== '') {
            $text .= "\nАдрес: {$address}";
        }

        if ($room !== null && $room !== '') {
            $text .= "\nКабинет: {$room}";
        }

        return $text."\n\nЗаберите документ в часы работы типографии.";
    }

    /** Формат страницы не поддерживается: печатаем только A4…A0 */
    public static function formatNotSupported(): string
    {
        return "Не могу напечатать: формат страницы не поддерживается.\n"
            .'Печатаю только '.PaperFormat::supportedLine()." — пришлите файл в одном из этих форматов.\n"
            .'Для A5/A6 и нестандартных размеров: Word → «Файл» → «Сохранить как» → PDF, размер страницы A4.';
    }

        /** Включили режим оператора */
    public static function operatorWelcome(): string
    {
        return "Включил режим оператора.\n\n"
            ."Пишите сообщения — их увидит сотрудник типографии и ответит в этом чате.\n"
            ."Файлы в этом режиме бот не считает.\n\n"
            .'Кнопка «Вернуться к боту» всегда внизу.';
    }
        /** Уведомление оператору: кто перешёл в режим оператора */
    public static function operatorRequested(?string $name, int $userId): string
    {
        $who = $name !== null && trim($name) !== '' ? trim($name) : 'имя не получено';

        return "Пользователь перешёл в режим оператора.\n\n"
            ."Имя: {$who}\n"
            ."id: {$userId}\n\n"
            .'Ответить: ВК → «Сообщения сообщества» → этот диалог.';
    }

    /** Вернулись к боту */
    public static function backToBot(): string
    {
        return "Снова бот.\n"
            .'Выберите корпус и пришлите PDF или DOCX — посчитаю страницы, назову цену и спрошу про ЧБ или цвет.';
    }

    /** Формат поддерживается, но в корпусе на него нет цены */
    public static function priceMissing(array $formats): string
    {
        return 'В этом корпусе нет цены на формат '.implode(', ', $formats).".\n"
            .'Выберите другой корпус.';
    }

    public static function orderPaid(int $id): string
    {
        return "Заказ №{$id} оплачен, передаю в печать.";
    }


    public static function fileCanceled(): string
    {
        return "Файл отменён и удалён.\nВыберите корпус и пришлите другой PDF.";
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

    /** Оговорка для DOCX: число страниц взято из метаданных файла */
    private static function docxNote(): string
    {
        return 'Типография откроет файл в Word — вёрстка останется вашей.';
    }
}