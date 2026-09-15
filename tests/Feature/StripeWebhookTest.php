<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\WebhookSignature;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'donation.currency' => 'cad',
        ]);
    }

    public function test_paid_checkout_completes_the_pending_donation(): void
    {
        $donation = $this->donation();

        $response = $this->postWebhook($this->payload($donation));

        $response->assertOk()->assertJson(['received' => true]);
        $donation->refresh();

        $this->assertSame('completed', $donation->status);
        $this->assertNotNull($donation->completed_at);
        $this->assertSame('25.00', $donation->eligible_amount);
        $this->assertSame('cs_test_webhook_123', $donation->stripe_session_id);
        $this->assertSame('pi_test_123', $donation->stripe_payment_intent_id);
        $this->assertSame('cus_test_123', $donation->stripe_customer_id);
        $this->assertSame('evt_test_completed_123', $donation->stripe_event_id);
    }

    public function test_metadata_donation_id_and_internal_reference_are_both_verified(): void
    {
        $donation = $this->donation();

        $this->postWebhook($this->payload($donation, [
            'metadata' => [
                'donation_id' => (string) $donation->id,
                'internal_reference' => 'DON-WRONG-REFERENCE',
            ],
        ]))->assertOk();

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_client_reference_id_can_identify_the_donation(): void
    {
        $donation = $this->donation();

        $payload = $this->payload($donation);
        unset($payload['data']['object']['metadata']);
        $payload['data']['object']['client_reference_id'] = $donation->internal_reference;

        $this->postWebhook($payload)->assertOk();

        $this->assertSame('completed', $donation->refresh()->status);
    }

    public function test_amount_mismatch_does_not_complete_the_donation(): void
    {
        $donation = $this->donation();

        $this->postWebhook($this->payload($donation, ['amount_total' => 2501]))->assertOk();

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_currency_mismatch_does_not_complete_the_donation(): void
    {
        $donation = $this->donation();

        $this->postWebhook($this->payload($donation, ['currency' => 'usd']))->assertOk();

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_unpaid_checkout_does_not_complete_the_donation(): void
    {
        $donation = $this->donation();

        $this->postWebhook($this->payload($donation, ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_unknown_donation_does_not_create_a_donation(): void
    {
        $payload = $this->payload(null, [
            'metadata' => [
                'donation_id' => '999999',
                'internal_reference' => 'DON-NOT-FOUND',
            ],
        ]);

        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_missing_metadata_is_ignored_without_creating_a_donation(): void
    {
        $payload = $this->payload(null);
        unset($payload['data']['object']['metadata'], $payload['data']['object']['client_reference_id']);

        $this->postWebhook($payload)->assertOk()->assertJson(['processed' => false]);
        $this->assertDatabaseCount('donations', 0);
    }

    public function test_invalid_signature_returns_400_and_does_not_complete_a_donation(): void
    {
        $donation = $this->donation();
        $payload = json_encode($this->payload($donation));

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
        ], $payload)->assertStatus(400);

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_missing_signature_returns_400(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->payload(null)))->assertStatus(400);
    }

    public function test_missing_webhook_secret_returns_400(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
        ], json_encode($this->payload(null)))->assertStatus(400);
    }

    public function test_invalid_json_payload_returns_400(): void
    {
        $rawPayload = '{invalid-json';

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($rawPayload, self::WEBHOOK_SECRET),
        ], $rawPayload)->assertStatus(400);
    }

    public function test_unsupported_event_is_acknowledged_without_modifying_the_donation(): void
    {
        $donation = $this->donation();
        $payload = $this->payload($donation);
        $payload['type'] = 'checkout.session.expired';

        $this->postWebhook($payload)->assertOk()->assertJson([
            'received' => true,
            'processed' => false,
        ]);

        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_same_event_is_idempotent(): void
    {
        $donation = $this->donation();
        $payload = $this->payload($donation);

        $this->postWebhook($payload)->assertOk();
        $completedAt = $donation->refresh()->completed_at;
        $this->postWebhook($payload)->assertOk()->assertJson(['processed' => false]);

        $this->assertSame('completed', $donation->refresh()->status);
        $this->assertTrue($completedAt->equalTo($donation->completed_at));
    }

    public function test_already_completed_donation_cannot_regress(): void
    {
        $donation = $this->donation(['status' => 'completed', 'completed_at' => now()]);
        $completedAt = $donation->completed_at;

        $this->postWebhook($this->payload($donation, ['id' => 'evt_different']))->assertOk();

        $donation->refresh();
        $this->assertSame('completed', $donation->status);
        $this->assertTrue($completedAt->equalTo($donation->completed_at));
    }

    public function test_session_object_must_be_a_checkout_session(): void
    {
        $donation = $this->donation();
        $payload = $this->payload($donation);
        $payload['data']['object']['object'] = 'payment_intent';

        $this->postWebhook($payload)->assertStatus(400);
        $this->assertSame('pending', $donation->refresh()->status);
    }

    public function test_webhook_route_is_public(): void
    {
        $this->assertSame('POST', app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/stripe/webhook', 'POST')
        )->methods()[0]);
    }

    private function donation(array $attributes = []): Donation
    {
        return Donation::factory()->create(array_merge([
            'internal_reference' => 'DON-WEBHOOK-123',
            'amount' => '25.00',
            'currency' => 'cad',
            'status' => 'pending',
            'stripe_session_id' => 'cs_test_webhook_123',
            'advantage_value' => '0.00',
        ], $attributes));
    }

    private function payload(?Donation $donation, array $overrides = []): array
    {
        $object = array_merge([
            'id' => 'cs_test_webhook_123',
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'currency' => 'cad',
            'client_reference_id' => $donation?->internal_reference,
            'metadata' => $donation ? [
                'donation_id' => (string) $donation->id,
                'internal_reference' => $donation->internal_reference,
            ] : [],
            'payment_intent' => 'pi_test_123',
            'customer' => 'cus_test_123',
        ], $overrides);

        return [
            'id' => $overrides['event_id'] ?? 'evt_test_completed_123',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $object],
        ];
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($body, self::WEBHOOK_SECRET),
        ], $body);
    }
}
