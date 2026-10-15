<?php

namespace App\Http\Controllers\Admin;

use App\Models\Campus;
use App\Models\CampusDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Расписание и реквизиты корпусов для панели типографии */
final class ScheduleController
{
    /** Дни недели: 1 — понедельник, 7 — воскресенье */
    private const WEEKDAYS = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    /** Всё состояние панели одним запросом: корпуса с расписанием + названия дней */
    public function state(): JsonResponse
    {
        $campuses = Campus::query()->with('days')->orderBy('id')->get();

        return response()->json([
            'campuses' => $campuses->map(fn (Campus $campus) => $this->campusPayload($campus))->all(),
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    /** Реквизиты корпуса и цена за страницу */
    public function updateCampus(Request $request, Campus $campus): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
            'price_per_page_kopecks' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $campus->update($data);

        return response()->json(['campus' => $this->campusPayload($campus->fresh('days'))]);
    }

    /** Расписание: чего нет в БД — создаём, что есть — обновляем */
    public function updateDays(Request $request, Campus $campus): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'array'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.is_working' => ['required', 'boolean'],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i'],
            'days.*.lunch_starts_at' => ['nullable', 'date_format:H:i'],
            'days.*.lunch_ends_at' => ['nullable', 'date_format:H:i'],
        ]);

        DB::transaction(function () use ($campus, $data): void {
            foreach ($data['days'] as $day) {
                CampusDay::query()->updateOrCreate(
                    ['campus_id' => $campus->id, 'weekday' => (int) $day['weekday']],
                    [
                        'is_working' => (bool) $day['is_working'],
                        'opens_at' => $day['opens_at'] ?? null,
                        'closes_at' => $day['closes_at'] ?? null,
                        'lunch_starts_at' => $day['lunch_starts_at'] ?? null,
                        'lunch_ends_at' => $day['lunch_ends_at'] ?? null,
                    ],
                );
            }
        });

        return response()->json(['campus' => $this->campusPayload($campus->fresh('days'))]);
    }

    /**
     * Формат ответа для панели.
     * Всегда отдаём 7 дней: чего нет в БД, то выключено — панель показывает полную неделю.
     *
     * @return array<string, mixed>
     */
    private function campusPayload(Campus $campus): array
    {
        $existing = $campus->days->keyBy(fn (CampusDay $day) => (int) $day->weekday);

        $days = [];

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $day = $existing->get($weekday);

            $days[] = [
                'weekday' => $weekday,
                'is_working' => (bool) $day?->is_working,
                'opens_at' => $this->clock($day?->opens_at),
                'closes_at' => $this->clock($day?->closes_at),
                'lunch_starts_at' => $this->clock($day?->lunch_starts_at),
                'lunch_ends_at' => $this->clock($day?->lunch_ends_at),
            ];
        }

        return [
            'id' => $campus->id,
            'code' => $campus->code,
            'title' => $campus->title,
            'address' => $campus->address,
            'room' => $campus->room,
            'is_active' => (bool) $campus->is_active,
            'price_per_page_kopecks' => (int) $campus->price_per_page_kopecks,
            'days' => $days,
        ];
    }

    /** TIME из БД («09:10:00») → значение для <input type="time"> («09:10») */
    private function clock(?string $value): ?string
    {
        return $value === null || $value === '' ? null : substr($value, 0, 5);
    }
}