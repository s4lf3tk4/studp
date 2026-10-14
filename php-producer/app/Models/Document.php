<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Document extends Model
{
    public const STATUS_NEW = 'new';           // файл принят, страницы считаются
    public const STATUS_COUNTED = 'counted';   // счёт выставлен, ждём оплату
    public const STATUS_PAID = 'paid';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    protected $fillable = [
        'platform', 'external_user_id', 'chat_id', 'message_mid',
        'original_name', 'mime', 'size_bytes', 'file_path', 'campus',
        'pages', 'status', 'error',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'int', 'pages' => 'int'];
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
}