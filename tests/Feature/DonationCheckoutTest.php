<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DonationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret_key' => 'sk_test_fake_secret_123',
            'services.stripe.publishable_key' => 'pk_test_fake_123',
            'donation.currency' => 'cad',
            'donation.frontend_url' => 'http://localhost:5173',
        ]);

        RateLimiter::clear('checkout');

        $this->stripeClient = new FakeStripeHttpClient([
            'id' => 'cs_test_abc123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            'mode' => 'payment',
            'payment_status' => 'unpaid',
        ]);

        \Stripe\ApiRequestor::setHttpClient($this->stripeClient);
    }

    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'donor_name' => 'Marie Kabasele',
            'donor_email' => 'marie@example.com',
            'donor_address' => '24 rue de la Paix',
            'donor_city' => 'Montréal',
            'donor_province' => 'Québec',
            'donor_postal_code' => 'H1G 5Z7',
            'donor_country' => 'Canada',
            'amount' => 25.00,
        ], $overrides);
    }

    public function test_valid_request_creates_pending_donation_and_returns_201(): void
    {
        $response = $this->postJson('/api/donations/checkout-session', $this->validPayload());

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Session de paiement créée.',
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            ])
            ->assertJsonStructure(['reference']);

        $donation = Donation::firstOrFail();

        $this->assertSame('pending', $donation->status);
        $this->assertSame('cs_test_abc123', $donation->stripe_session_id);
        $this->assertSame('25.00', $donation->amount);
        $this->assertSame('cad', $donation->currency);
        $this->assertSame('Marie Kabasele', $donation->donor_name);
        $this->assertSame('marie@example.com', $donation->donor_email);
        $this->assertSame('0.00', $donation->advantage_value);
    }

    public function test_donor_name_is_required(): void
    {
        $payload = $this->validPayload(['donor_name' => '']);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donor_name']);
    }

    public function test_donor_email_is_required(): void
    {
        $payload = $this->validPayload(['donor_email' => '']);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donor_email']);
    }

    public function test_donor_email_must_be_valid(): void
    {
        $payload = $this->validPayload(['donor_email' => 'pas-une-adresse']);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['donor_email']);
    }

    public function test_amount_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['amount']);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_amount_below_minimum_returns_422(): void
    {
        $payload = $this->validPayload(['amount' => 4.99]);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_amount_above_maximum_returns_422(): void
    {
        $payload = $this->validPayload(['amount' => 10000.01]);

        $this->postJson('/api/donations/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_client_currency_is_ignored_and_server_currency_is_used(): void
    {
        $this->postJson('/api/donations/checkout-session', $this->validPayload([
            'currency' => 'usd',
        ]))->assertStatus(201);

        $this->assertSame('cad', $this->stripeClient->lastParams['line_items'][0]['price_data']['currency']);
        $this->assertSame(2500, $this->stripeClient->lastParams['line_items'][0]['price_data']['unit_amount']);

        $this->assertDatabaseHas('donations', [
            'currency' => 'cad',
        ]);
    }

    public function test_stripe_session_contains_metadata_and_client_reference_id(): void
    {
        $this->postJson('/api/donations/checkout-session', $this->validPayload())->assertStatus(201);

        $donation = Donation::firstOrFail();

        $this->assertSame('payment', $this->stripeClient->lastParams['mode']);
        $this->assertSame($donation->internal_reference, $this->stripeClient->lastParams['client_reference_id']);
        $this->assertSame($donation->id, $this->stripeClient->lastParams['metadata']['donation_id']);
        $this->assertSame($donation->internal_reference, $this->stripeClient->lastParams['metadata']['internal_reference']);
    }

    public function test_stripe_session_uses_frontend_urls_with_reference(): void
    {
        $this->postJson('/api/donations/checkout-session', $this->validPayload())->assertStatus(201);

        $donation = Donation::firstOrFail();

        $this->assertSame(
            'http://localhost:5173/don/success?reference='.$donation->internal_reference,
            $this->stripeClient->lastParams['success_url']
        );
        $this->assertSame(
            'http://localhost:5173/don/cancel?reference='.$donation->internal_reference,
            $this->stripeClient->lastParams['cancel_url']
        );
    }

    public function test_payment_intent_id_is_stored_when_returned_by_stripe(): void
    {
        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_pi123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_pi123',
            'mode' => 'payment',
            'payment_status' => 'unpaid',
            'payment_intent' => 'pi_test_456',
        ]);

        $this->postJson('/api/donations/checkout-session', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseHas('donations', [
            'stripe_session_id' => 'cs_test_pi123',
            'stripe_payment_intent_id' => 'pi_test_456',
        ]);
    }

    public function test_each_checkout_gets_a_unique_internal_reference(): void
    {
        $this->postJson('/api/donations/checkout-session', $this->validPayload())->assertStatus(201);

        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_def456',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_def456',
            'mode' => 'payment',
            'payment_status' => 'unpaid',
        ]);

        $this->postJson('/api/donations/checkout-session', $this->validPayload([
            'donor_email' => 'paul@example.com',
        ]))->assertStatus(201);

        $donations = Donation::orderBy('id')->get();

        $this->assertCount(2, $donations);
        $this->assertNotSame($donations[0]->internal_reference, $donations[1]->internal_reference);
    }

    public function test_response_never_contains_the_stripe_secret_key(): void
    {
        $response = $this->postJson('/api/donations/checkout-session', $this->validPayload());

        $response->assertStatus(201);
        $this->assertStringNotContainsString('sk_test_fake_secret_123', $response->getContent());
        $this->assertStringNotContainsString('secret_key', $response->getContent());
    }

    public function test_returns_503_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret_key' => null]);

        $this->postJson('/api/donations/checkout-session', $this->validPayload())
            ->assertStatus(503);

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_returns_502_without_internal_details_when_stripe_fails(): void
    {
        $this->stripeClient->responseCode = 400;
        $this->stripeClient->responseBody = json_encode([
            'error' => [
                'message' => 'Invalid API Key provided',
                'type' => 'invalid_request_error',
            ],
        ]);

        $this->postJson('/api/donations/checkout-session', $this->validPayload())
            ->assertStatus(502)
            ->assertJson([
                'message' => 'Le service de paiement rencontre un problème. Veuillez réessayer plus tard.',
            ]);

        $this->assertDatabaseHas('donations', [
            'status' => 'failed',
        ]);
    }
}

/**
 * Minimal Stripe HTTP client used to intercept SDK requests in tests.
 */
class FakeStripeHttpClient
{
    public array $lastParams = [];

    public string $responseBody;

    public int $responseCode = 200;

    public function __construct(array $defaultResponse = [])
    {
        $this->responseBody = json_encode($defaultResponse);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = null, $maxNetworkRetries = null): array
    {
        $this->lastParams = $params;

        return [$this->responseBody, $this->responseCode, []];
    }
}