<?php

namespace App\Services;

use App\Models\Donation;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeDonationService
{
    /**
     * Create a Stripe Checkout Session for the given pending donation.
     */
    public function createCheckoutSession(Donation $donation): Session
    {
        Stripe::setApiKey(config('services.stripe.secret_key'));

        return Session::create($this->checkoutPayload($donation));
    }

    /**
     * Build the Stripe Checkout Session creation payload.
     *
     * The currency always comes from the server configuration; the amount is
     * converted to the smallest currency unit (cents) on the server side.
     *
     * @return array<string, mixed>
     */
    public function checkoutPayload(Donation $donation): array
    {
        $frontendUrl = rtrim((string) config('donation.frontend_url'), '/');
        $reference = rawurlencode($donation->internal_reference);

        return [
            'mode' => 'payment',
            'client_reference_id' => $donation->internal_reference,
            'customer_email' => $donation->donor_email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => (string) config('donation.currency'),
                    'unit_amount' => $this->toCents($donation->amount),
                    'product_data' => [
                        'name' => 'Don à '.config('donation.organization.legal_name'),
                    ],
                ],
            ]],
            'metadata' => [
                'donation_id' => $donation->id,
                'internal_reference' => $donation->internal_reference,
            ],
            'success_url' => $frontendUrl.'/don/success?reference='.$reference,
            'cancel_url' => $frontendUrl.'/don/cancel?reference='.$reference,
        ];
    }

    /**
     * Convert a monetary amount into the currency smallest unit (cents).
     */
    private function toCents(string|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}