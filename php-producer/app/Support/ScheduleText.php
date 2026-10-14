<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\CampusDay;
use Illuminate\Support\Facades\Log;

final class ScheduleText
{
    /** ISO-8601: 1 = Пн */
    private const DAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    /** null — корпуса нет в БД или БД недоступна: блок просто не показываем */
    public static function forCampus(string $code): ?string
    {
        try {
            $campus = Campus::query()
                ->with('days')
                ->where('code', $code)
                ->where('is_active', true)
                ->first();
        } catch (\Throwable $e) {
            Log::warning('Не прочитал расписание корпуса: '.$e->getMessage(), ['campus' => $code]);

            return null;
        }

        if ($campus === null) {
            return null;
        }

        $byDay = [];

        foreach ($campus->days as $day) {
            $byDay[(int) $day->weekday] = $day;
        }

        $lines = [];

        $address = trim((string) $campus->address);
        $room = trim((string) $campus->room);

        if ($address !== '') {
            $lines[] = 'Адрес: '.$address.($room !== '' ? ', каб. '.$room : '');
        } elseif ($room !== '') {
            $lines[] = 'Кабинет: '.$room;
        }

        foreach (self::runs($byDay) as $run) {
            $lines[] = self::line($run['days'], $run['day']);
        }

        if ($lines === []) {
            return null;
        }

        return implode("\n", $lines);
    }

    /**
     * Подряд идущие дни с одинаковыми настройками → одна строка вывода.
     *
     * @param  array<int, CampusDay|null>  $byDay
     * @return array<int, array{key: string, day: CampusDay|null, days: array<int, int>}>
     */
    private static function runs(array $byDay): array
    {
        $runs = [];

        foreach (range(1, 7) as $weekday) {
            $day = $byDay[$weekday] ?? null;
            $key = self::signature($day);
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['key'] === $key) {
                $runs[$last]['days'][] = $weekday;

                continue;
            }

            $runs[] = ['key' => $key, 'day' => $day, 'days' => [$weekday]];
        }

        return $runs;
    }

    /** Одинаковые настройки → одинаковый ключ, тогда дни склеиваются */
    private static function signature(?CampusDay $day): string
    {
        if ($day === null || ! $day->is_working) {
            return 'off';
        }

        return implode('|', [
            $day->opens_at, $day->closes_at, $day->lunch_starts_at, $day->lunch_ends_at,
        ]);
    }

    /** @param  array<int, int>  $days */
    private static function line(array $days, ?CampusDay $day): string
    {
        $label = self::range($days);

        if ($day === null || ! $day->is_working) {
            return $label.' — выходной';
        }

        $text = $label.' — '.self::time($day->opens_at).'–'.self::time($day->closes_at);

        if ($day->lunch_starts_at !== null && $day->lunch_ends_at !== null) {
            $text .= ', обед '.self::time($day->lunch_starts_at).'–'.self::time($day->lunch_ends_at);
        }

        return $text;
    }

    /** @param  array<int, int>  $days */
    private static function range(array $days): string
    {
        $first = $days[0];
        $last = $days[count($days) - 1];

        return $first === $last ? self::DAYS[$first] : self::DAYS[$first].'–'.self::DAYS[$last];
    }

    private static function time(?string $value): string
    {
        return substr((string) $value, 0, 5); // 09:00:00 → 09:00
    }
}