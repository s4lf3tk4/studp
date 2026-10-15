<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Payment extends Model
{
    protected $fillable = [
        'uuid', 'document_id', 'pages', 'unit_price', 'amount',
        'currency', 'status', 'provider', 'confirmation_url', 'receipt_email',
        'provider_payment_id', 'paid_at', 'notification',
    ];

    protected function casts(): array
    {
        return [
            'pages' => 'int',
            'unit_price' => 'int',
            'amount' => 'int',
            'paid_at' => 'datetime',
            'notification' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}