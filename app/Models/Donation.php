<?php

namespace App\Models;

use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $internal_reference
 * @property string $amount
 * @property string $currency
 * @property string $donor_name
 * @property string $donor_email
 * @property string|null $donor_address
 * @property string|null $donor_city
 * @property string|null $donor_province
 * @property string|null $donor_postal_code
 * @property string|null $donor_country
 * @property string $advantage_value
 * @property string|null $advantage_description
 * @property string|null $eligible_amount
 * @property Carbon|null $receipt_issued_at
 * @property string $status
 * @property string|null $stripe_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_event_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'internal_reference',
    'amount',
    'currency',
    'donor_name',
    'donor_email',
    'donor_address',
    'donor_city',
    'donor_province',
    'donor_postal_code',
    'donor_country',
    'advantage_value',
    'advantage_description',
    'eligible_amount',
    'receipt_issued_at',
    'status',
    'stripe_session_id',
    'stripe_payment_intent_id',
    'stripe_customer_id',
    'stripe_event_id',
    'completed_at',
])]
// Stripe identifiers are purely internal and must never leak to clients.
#[Hidden([
    'stripe_session_id',
    'stripe_payment_intent_id',
    'stripe_customer_id',
    'stripe_event_id',
])]
class Donation extends Model
{
    /** @use HasFactory<DonationFactory> */
    use HasFactory;

    /**
     * List of donation statuses.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        'pending',
        'completed',
        'failed',
        'cancelled',
        'refunded',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'advantage_value' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'receipt_issued_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the official receipt issued for this donation.
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }
}
