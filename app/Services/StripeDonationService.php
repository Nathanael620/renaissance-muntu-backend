<?php

namespace App\Services;

use App\Models\Donation;
use App\Support\DonationCurrencyAmount;
use InvalidArgumentException;
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

        return Session::create($this->checkoutPayload($donation), [
            'idempotency_key' => $this->checkoutIdempotencyKey($donation),
        ]);
    }

    public function retrieveCheckoutSession(Donation $donation): Session
    {
        Stripe::setApiKey(config('services.stripe.secret_key'));

        if (blank($donation->stripe_session_id)) {
            throw new InvalidArgumentException('Donation has no Stripe Checkout session.');
        }

        return Session::retrieve($donation->stripe_session_id);
    }

    /**
     * Build the Stripe Checkout Session creation payload.
     *
     * The persisted currency is authoritative; Stripe expects amounts in minor units.
     *
     * @return array<string, mixed>
     */
    public function checkoutPayload(Donation $donation): array
    {
        $frontendUrls = array_map('trim', explode(',', (string) config('donation.frontend_url')));
        $frontendUrl = rtrim($frontendUrls[0] ?? '', '/');
        $reference = rawurlencode($donation->internal_reference);

        return [
            'mode' => 'payment',
            'client_reference_id' => $donation->internal_reference,
            'customer_email' => $donation->donor_email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($donation->currency),
                    'unit_amount' => DonationCurrencyAmount::toMinorUnits($donation->amount, $donation->currency),
                    'product_data' => [
                        'name' => 'Don à '.config('donation.organization.legal_name'),
                    ],
                ],
            ]],
            'metadata' => [
                'donation_id' => $donation->id,
                'internal_reference' => $donation->internal_reference,
            ],
            'success_url' => $frontendUrl.'/don/success?reference='.$reference.'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.'/don/cancel?reference='.$reference,
        ];
    }

    private function checkoutIdempotencyKey(Donation $donation): string
    {
        $key = $donation->checkout_idempotency_key ?? $donation->internal_reference;

        return 'donation-checkout-'.hash('sha256', (string) $key);
    }
}