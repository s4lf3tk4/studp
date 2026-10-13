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
                    'type' => 'text',
                    'label' => $label,
                    'payload' => json_encode(['command' => $command], JSON_UNESCAPED_UNICODE),
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