<?php

namespace App\Models;

use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $donation_id
 * @property string $number
 * @property string|null $file_path
 * @property string|null $file_hash
 * @property Carbon $issued_at
 * @property Carbon|null $email_queued_at
 * @property Carbon|null $email_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'donation_id',
    'number',
    'file_path',
    'file_hash',
    'issued_at',
    'email_queued_at',
    'email_sent_at',
])]
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'email_queued_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    /**
     * Get the donation this receipt was issued for.
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
