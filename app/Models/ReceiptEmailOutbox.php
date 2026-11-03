<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $donation_id
 * @property string $status
 * @property int $attempts
 * @property int $dispatch_attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $reserved_at
 * @property string|null $claim_token
 * @property string|null $last_error_type
 */
#[Fillable([
    'donation_id',
    'status',
    'attempts',
    'dispatch_attempts',
    'available_at',
    'queued_at',
    'reserved_at',
    'claim_token',
    'last_error_type',
])]
class ReceiptEmailOutbox extends Model
{
    protected $table = 'receipt_email_outbox';

    public const MAX_DELIVERY_ATTEMPTS = 5;

    public const MAX_DISPATCH_ATTEMPTS = 5;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'queued_at' => 'datetime',
            'reserved_at' => 'datetime',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
