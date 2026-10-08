<?php

namespace App\Services;

final class MessageSourceDetector
{
    /** @return 'max'|'vk'|null */
    public function detect(array $payload): ?string
    {
        // if (is_int($payload['update_id'] ?? null)
        //     && array_intersect(array_keys($payload), ['message', 'edited_message', 'channel_post', 'callback_query'])) {
        //     return 'telegram';
        // }

        if (is_string($payload['type'] ?? null) && is_int($payload['group_id'] ?? null)) {
            return 'vk';
        }

        if (is_string($payload['update_type'] ?? null)) {
            return 'max';
        }

        return null;
    }
}
