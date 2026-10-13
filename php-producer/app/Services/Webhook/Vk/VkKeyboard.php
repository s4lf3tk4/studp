<?php

namespace App\Services\Webhook\Vk;

use App\Services\Webhook\BotCommand;

final class VkKeyboard
{

    public static function chosenCampus(): array
    {
        $row = [];

        foreach (BotCommand::CAMPUSES as $command => $label) {
            $row[] = [
                'action' => [
                    'type' => 'text',                                    // ← тип кнопки
                    'label' => $label,                                   // ← что видит пользователь
                    'payload' => json_encode(['command' => $command], JSON_UNESCAPED_UNICODE),  // ← что приходит боту
                ],
                'color' => 'primary',
            ];
        }

        return [
            'one_time' => false,
            'buttons' => [$row],
        ];
    }
    

}