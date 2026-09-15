<?php

namespace Tests\Unit;

use App\Models\Donation;
use App\Services\StripeDonationService;
use Tests\TestCase;

class StripeDonationServiceTest extends TestCase
{
    private function donation(array $attributes = []): Donation
    {
        return Donation::factory()->make(array_merge([
            'internal_reference' => 'DON-00000001',
            'donor_email' => 'marie@example.com',
            'amount' => '25.00',
        ], $attributes));
    }

    public function test_payload_uses_server_currency_and_converts_amount_to_cents(): void
    {
        config([
            'donation.currency' => 'cad',
            'donation.frontend_url' => 'http://localhost:5173',
        ]);

        $payload = (new StripeDonationService())->checkoutPayload($this->donation());

        $this->assertSame('cad', $payload['line_items'][0]['price_data']['currency']);
        $this->assertSame(2500, $payload['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_payload_never_accepts_client_currency(): void
    {
        config([
            'donation.currency' => 'eur',
            'donation.frontend_url' => 'http://localhost:5173',
        ]);

        $payload = (new StripeDonationService())->checkoutPayload($this->donation(['currency' => 'usd']));

        $this->assertSame('eur', $payload['line_items'][0]['price_data']['currency']);
    }

    public function test_payload_contains_metadata_and_client_reference_for_the_webhook(): void
    {
        config([
            'donation.currency' => 'cad',
            'donation.frontend_url' => 'http://localhost:5173',
        ]);

        $payload = (new StripeDonationService())->checkoutPayload($this->donation());

        $this->assertSame('payment', $payload['mode']);
        $this->assertSame('DON-00000001', $payload['client_reference_id']);
        $this->assertSame('DON-00000001', $payload['metadata']['internal_reference']);
        $this->assertSame('marie@example.com', $payload['customer_email']);
    }

    public function test_payload_builds_frontend_success_and_cancel_urls(): void
    {
        config([
            'donation.currency' => 'cad',
            'donation.frontend_url' => 'http://localhost:5173',
        ]);

        $payload = (new StripeDonationService())->checkoutPayload($this->donation());

        $this->assertSame(
            'http://localhost:5173/don/success?reference=DON-00000001',
            $payload['success_url']
        );
        $this->assertSame(
            'http://localhost:5173/don/cancel?reference=DON-00000001',
            $payload['cancel_url']
        );
    }
}