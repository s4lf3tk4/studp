<?php

namespace App\Models;

use App\Support\PrintMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Document extends Model
{
    public const STATUS_NEW = 'new';                   // файл принят, страницы считаются
    public const STATUS_COUNTED = 'counted';           // страницы посчитаны, ждём решения клиента
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';   // клиент подтвердил, ждём оплату
    public const STATUS_PAID = 'paid';                 // оплачен, ждём печати
    public const STATUS_PRINTED = 'printed';           // напечатан, ждёт клиента
    public const STATUS_ISSUED = 'issued';             // клиент забрал, файл удалён

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Считается',
        self::STATUS_COUNTED => 'Ждёт подтверждения',
        self::STATUS_AWAITING_PAYMENT => 'Ждёт оплаты',
        self::STATUS_PAID => 'Ждёт печати',
        self::STATUS_PRINTED => 'Напечатан',
        self::STATUS_ISSUED => 'Выдан',
    ];

    protected $fillable = [
        'platform', 'external_user_id', 'chat_id', 'message_mid',
        'original_name', 'mime', 'size_bytes', 'file_path', 'campus',
        'pages', 'print_mode', 'special_pages', 'pages_by_format', 'status', 'error',
        
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'int',
            'pages' => 'int',
            'pages_by_format' => 'array',
            'special_pages' => 'array',
        ];
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Отметить оплату: платёж закрывается, документ переходит в paid */
    public function markPaid(?string $providerPaymentId = null): void
    {
        $payment = $this->payments()->where('status', 'pending')->latest('id')->first();

        $payment?->update([
            'status' => 'paid',
            'paid_at' => now(),
            'provider_payment_id' => $providerPaymentId,
        ]);

        $this->update(['status' => self::STATUS_PAID]);
    }

    public function markPrinted(): void
    {
        $this->update(['status' => self::STATUS_PRINTED]);
    }

    /** Сколько оплаченных документов ждёт печати в этом корпусе */
    public static function queuedForCampus(string $campusCode): int
    {
        return self::query()
            ->where('campus', $campusCode)
            ->where('status', self::STATUS_PAID)
            ->count();
    }

    /** Клиент забрал документ: файла больше нет, запись остаётся в истории */
    public function markIssued(): void
    {
        $this->update(['status' => self::STATUS_ISSUED, 'file_path' => null]);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Страницы другого режима для админки: «Цветные: 1, 4» или «—» */
    public function specialPagesLabel(): string
    {
        $pages = is_array($this->special_pages) ? $this->special_pages : [];

        if ($pages === []) {
            return '—';
        }

        $label = $this->print_mode === PrintMode::COLOR ? 'ЧБ' : 'Цветные';

        return $label.': '.implode(', ', array_map('strval', $pages));
    }

    /** Режим печати для админки: «ЧБ», «Цветной» или «—» у документов до выбора режима */
    public function modeLabel(): string
    {
        return PrintMode::label($this->print_mode) ?? '—';
    }
    /** Форматы страниц для админки и сообщений: «A4 ×10, A3 ×2» */
    public function formatLabels(): string
    {
        return \App\Support\BotTexts::formatsLine(
            is_array($this->pages_by_format) ? $this->pages_by_format : [],
        );
    }

}