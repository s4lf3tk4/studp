<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** С кем общается пользователь: с ботом или с живым оператором */
final class ChatState extends Model
{
    public const MODE_BOT = 'bot';
    public const MODE_OPERATOR = 'operator';

    protected $fillable = ['platform', 'external_user_id', 'name', 'mode'];

    /** Текущий режим; нет записи — значит бот */
    public static function modeFor(string $platform, int $userId): string
    {
        $mode = self::query()
            ->where('platform', $platform)
            ->where('external_user_id', $userId)
            ->value('mode');

        return $mode === self::MODE_OPERATOR ? self::MODE_OPERATOR : self::MODE_BOT;
    }

    public static function isOperator(string $platform, int $userId): bool
    {
        return self::modeFor($platform, $userId) === self::MODE_OPERATOR;
    }

    public static function setMode(string $platform, int $userId, string $mode, ?string $name = null): void
    {
        $values = ['mode' => $mode === self::MODE_OPERATOR ? self::MODE_OPERATOR : self::MODE_BOT];

        // Имя не перетираем пустым ответом API — оно нужно только для панели
        if ($name !== null && trim($name) !== '') {
            $values['name'] = trim($name);
        }

        self::query()->updateOrCreate(
            ['platform' => $platform, 'external_user_id' => $userId],
            $values,
        );
    }

    /** Кто сейчас ждёт оператора — понадобится панели */
    public static function operatorQueue(string $platform): array
    {
        return self::query()
            ->where('platform', $platform)
            ->where('mode', self::MODE_OPERATOR)
            ->orderBy('updated_at')
            ->get(['external_user_id', 'name', 'updated_at'])
            ->toArray();
    }
}