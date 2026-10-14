<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\CampusDay;
use Illuminate\Database\Seeder;

class CampusScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // 1 = Пн … 7 = Вс. null — выходной.
        // is_active => false — корпус выключен: кнопка в VK станет красной, выбор запрещён.
        $schedule = [
            'campus1' => [
                'title' => 'Корпус 1',
                'address' => 'ул. Ленина, 12',
                'room' => '305',
                'is_active' => true,
                'days' => [
                    1 => ['opens_at' => '09:10', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    2 => ['opens_at' => '09:20', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    3 => ['opens_at' => '09:30', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    4 => ['opens_at' => '09:40', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    5 => ['opens_at' => '09:50', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    6 => ['opens_at' => '10:20', 'closes_at' => '15:00'],
                    7 => null,
                ],
            ],
            'campus2' => [
                'title' => 'Корпус 2',
                'address' => 'ул. Мира, 5',
                'room' => '101',
                'is_active' => true,
                'days' => [
                    1 => ['opens_at' => '08:30', 'closes_at' => '17:30', 'lunch_starts_at' => '12:30', 'lunch_ends_at' => '13:30'],
                    2 => ['opens_at' => '08:30', 'closes_at' => '17:30', 'lunch_starts_at' => '12:30', 'lunch_ends_at' => '13:30'],
                    3 => ['opens_at' => '08:30', 'closes_at' => '17:30', 'lunch_starts_at' => '12:30', 'lunch_ends_at' => '13:30'],
                    4 => ['opens_at' => '08:30', 'closes_at' => '17:30', 'lunch_starts_at' => '12:30', 'lunch_ends_at' => '13:30'],
                    5 => ['opens_at' => '08:30', 'closes_at' => '17:30', 'lunch_starts_at' => '12:30', 'lunch_ends_at' => '13:30'],
                    6 => null,
                    7 => null,
                ],
            ],
            'campus3' => [
                'title' => 'Корпус 3',
                'address' => 'пр. Строителей, 40',
                'room' => '212',
                'is_active' => true,
                'days' => [
                    1 => ['opens_at' => '09:00', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    2 => ['opens_at' => '09:00', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    3 => ['opens_at' => '09:00', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    4 => ['opens_at' => '09:00', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    5 => ['opens_at' => '09:00', 'closes_at' => '18:00', 'lunch_starts_at' => '13:00', 'lunch_ends_at' => '14:00'],
                    6 => null,
                    7 => null,
                ],
            ],
            'campus4' => [
                'title' => 'Корпус 4',
                'address' => 'ул. Садовая, 8',
                'room' => '3',
                'is_active' => false,   // ← для проверки красной кнопки
                'days' => [
                    1 => ['opens_at' => '09:00', 'closes_at' => '17:00', 'lunch_starts_at' => '12:00', 'lunch_ends_at' => '13:00'],
                    2 => ['opens_at' => '09:00', 'closes_at' => '17:00', 'lunch_starts_at' => '12:00', 'lunch_ends_at' => '13:00'],
                    3 => ['opens_at' => '09:00', 'closes_at' => '17:00', 'lunch_starts_at' => '12:00', 'lunch_ends_at' => '13:00'],
                    4 => ['opens_at' => '09:00', 'closes_at' => '17:00', 'lunch_starts_at' => '12:00', 'lunch_ends_at' => '13:00'],
                    5 => ['opens_at' => '09:00', 'closes_at' => '17:00', 'lunch_starts_at' => '12:00', 'lunch_ends_at' => '13:00'],
                    6 => ['opens_at' => '11:00', 'closes_at' => '15:00'],
                    7 => null,
                ],
            ],
        ];

        foreach ($schedule as $code => $data) {
            $campus = Campus::updateOrCreate(
                ['code' => $code],
                [
                    'title' => $data['title'],
                    'address' => $data['address'] ?? null,
                    'room' => $data['room'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ],
            );

            foreach (range(1, 7) as $weekday) {
                $day = $data['days'][$weekday] ?? null;

                CampusDay::updateOrCreate(
                    ['campus_id' => $campus->id, 'weekday' => $weekday],
                    [
                        // null — выходной; можно задать is_working => false явно, сохранив часы
                        'is_working' => $day !== null && ($day['is_working'] ?? true),
                        'opens_at' => $day['opens_at'] ?? null,
                        'closes_at' => $day['closes_at'] ?? null,
                        'lunch_starts_at' => $day['lunch_starts_at'] ?? null,
                        'lunch_ends_at' => $day['lunch_ends_at'] ?? null,
                    ],
                );
            }
        }
    }
}