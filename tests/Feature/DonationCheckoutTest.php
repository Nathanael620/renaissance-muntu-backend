<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Tests\Support\DonationTestSchema;
use Tests\TestCase;

class DonationCheckoutTest extends TestCase
{
    private const IDEMPOTENCY_KEY = '9fd60576-9b48-4e51-94ca-4fd29ce42c13';

    private FakeStripeHttpClient $stripeClient;

    protected function setUp(): void
    {
        parent::setUp();

        DonationTestSchema::create();
        config([
            'services.stripe.secret_key' => 'sk_test_fake_secret_123',
            'donation.currency' => 'cad',
            'donation.amounts.min' => 5,
            'donation.amounts.max' => 10000,
            'donation.frontend_url' => 'http://localhost:5173',
        ]);
        RateLimiter::clear('checkout');

        $this->stripeClient = new FakeStripeHttpClient([
            'id' => 'cs_test_abc123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            'mode' => 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ]);

        ApiRequestor::setHttpClient($this->stripeClient);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_valid_checkout_creates_pending_donation_and_returns_201(): void
    {
        $response = $this->postCheckout($this->validPayload());

        $response->assertCreated()
            ->assertJson([
                'message' => 'Session de paiement créée.',
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            ])
            ->assertJsonStructure(['reference']);

        $donation = Donation::query()->firstOrFail();
        $this->assertSame('pending', $donation->status);
        $this->assertSame('cs_test_abc123', $donation->stripe_session_id);
        $this->assertSame('25.00', $donation->amount);
        $this->assertSame('cad', $donation->currency);
        $this->assertSame(self::IDEMPOTENCY_KEY, $donation->checkout_idempotency_key);
        $this->assertSame(2500, $this->stripeClient->lastParams['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_checkout_requires_idempotency_key_header_even_if_json_contains_a_key(): void
    {
        $this->postJson('/api/donations/checkout-session', [
            ...$this->validPayload(),
            'idempotency_key' => self::IDEMPOTENCY_KEY,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_cors_preflight_allows_the_idempotency_header(): void
    {
        $response = $this->call('OPTIONS', '/api/donations/checkout-session', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,idempotency-key',
        ]);

        $response->assertNoContent();
        $this->assertStringContainsString(
            'idempotency-key',
            strtolower($response->headers->get('Access-Control-Allow-Headers', ''))
        );
    }

    public function test_donor_name_is_required(): void
    {
        $this->postCheckout($this->validPayload(['donor_name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['donor_name']);
    }

    public function test_donor_email_is_required(): void
    {
        $this->postCheckout($this->validPayload(['donor_email' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['donor_email']);
    }

    public function test_donor_email_must_be_valid(): void
    {
        $this->postCheckout($this->validPayload(['donor_email' => 'pas-une-adresse']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['donor_email']);
    }

    public function test_amount_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['amount']);

        $this->postCheckout($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_checkout_rejects_malformed_idempotency_key_with_422(): void
    {
        $this->postCheckout($this->validPayload(), 'too-short')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_repeated_checkout_reuses_existing_session_and_returns_200(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();

        $this->postCheckout($this->validPayload())
            ->assertOk()
            ->assertJson([
                'message' => 'Session de paiement déjà créée.',
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            ]);

        $this->assertSame(1, Donation::query()->count());
        $this->assertSame(1, count(array_filter(
            $this->stripeClient->requests,
            fn (array $request): bool => strtoupper($request['method']) === 'POST'
        )));
    }

    public function test_distinct_idempotency_keys_allow_two_donations_for_the_same_amount(): void
    {
        $this->postCheckout($this->validPayload(['donor_email' => 'one@example.com']))->assertCreated();

        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_second',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_second',
            'mode' => 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ], JSON_THROW_ON_ERROR);

        $this->postCheckout(
            $this->validPayload(['donor_email' => 'two@example.com']),
            'a1b2c3d4-e5f6-47a8-b9c0-d1e2f3a4b5c6'
        )->assertCreated();

        $donations = Donation::query()->orderBy('id')->get();
        $this->assertCount(2, $donations);
        $this->assertNotSame($donations[0]->internal_reference, $donations[1]->internal_reference);
        $this->assertSame(2, count($this->stripeClient->requests));
    }

    public function test_same_idempotency_key_with_different_payload_returns_409(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();

        $this->postCheckout($this->validPayload(['amount' => 26.00]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cette clé d\'idempotence a déjà été utilisée avec des données différentes.');

        $this->assertSame(1, Donation::query()->count());
    }

    public function test_amount_with_additional_fractional_digits_returns_422(): void
    {
        $this->postCheckout($this->validPayload(['amount' => 25.001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_amount_below_minimum_returns_422(): void
    {
        $this->postCheckout($this->validPayload(['amount' => 4.99]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_amount_above_maximum_returns_422(): void
    {
        $this->postCheckout($this->validPayload(['amount' => 10000.01]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_client_currency_is_ignored_and_server_currency_is_used(): void
    {
        $this->postCheckout($this->validPayload(['currency' => 'usd']))->assertCreated();

        $this->assertSame('cad', $this->stripeClient->lastParams['line_items'][0]['price_data']['currency']);
        $this->assertSame('cad', Donation::query()->firstOrFail()->currency);
    }

    public function test_stripe_session_contains_metadata_and_client_reference_id(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();

        $donation = Donation::query()->firstOrFail();
        $this->assertSame('payment', $this->stripeClient->lastParams['mode']);
        $this->assertSame($donation->internal_reference, $this->stripeClient->lastParams['client_reference_id']);
        $this->assertSame($donation->id, $this->stripeClient->lastParams['metadata']['donation_id']);
        $this->assertSame(
            $donation->internal_reference,
            $this->stripeClient->lastParams['metadata']['internal_reference']
        );
    }

    public function test_checkout_uses_the_established_frontend_return_routes(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();
        $donation = Donation::query()->firstOrFail();

        $this->assertSame(
            'http://localhost:5173/don/success?reference='.$donation->internal_reference.'&session_id={CHECKOUT_SESSION_ID}',
            $this->stripeClient->lastParams['success_url']
        );
        $this->assertSame(
            'http://localhost:5173/don/cancel?reference='.$donation->internal_reference,
            $this->stripeClient->lastParams['cancel_url']
        );
    }

    public function test_payment_intent_id_is_stored_when_stripe_returns_it(): void
    {
        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_pi123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_pi123',
            'mode' => 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => 'pi_test_456',
        ], JSON_THROW_ON_ERROR);

        $this->postCheckout($this->validPayload())->assertCreated();

        $donation = Donation::query()->firstOrFail();
        $this->assertSame('cs_test_pi123', $donation->stripe_session_id);
        $this->assertSame('pi_test_456', $donation->stripe_payment_intent_id);
    }

    public function test_each_distinct_checkout_gets_a_unique_internal_reference(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();

        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_second',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_second',
            'mode' => 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ], JSON_THROW_ON_ERROR);

        $this->postCheckout(
            $this->validPayload(['donor_email' => 'paul@example.com']),
            'a1b2c3d4-e5f6-47a8-b9c0-d1e2f3a4b5c6'
        )->assertCreated();

        $donations = Donation::query()->orderBy('id')->get();
        $this->assertCount(2, $donations);
        $this->assertNotSame($donations[0]->internal_reference, $donations[1]->internal_reference);
    }

    public function test_response_does_not_expose_stripe_secret_configuration(): void
    {
        $response = $this->postCheckout($this->validPayload());

        $response->assertCreated();
        $this->assertStringNotContainsString('sk_test_fake_secret_123', $response->getContent());
        $this->assertStringNotContainsString('secret_key', $response->getContent());
    }

    public function test_checkout_retries_an_ambiguous_stripe_response_with_the_same_provider_key(): void
    {
        $this->stripeClient->responseCode = 500;
        $this->stripeClient->responseBody = json_encode([
            'error' => [
                'message' => 'Temporary Stripe error',
                'type' => 'api_error',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postCheckout($this->validPayload())->assertStatus(502);

        $this->stripeClient->responseCode = 200;
        $this->stripeClient->responseBody = json_encode([
            'id' => 'cs_test_abc123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
            'mode' => 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ], JSON_THROW_ON_ERROR);

        $this->postCheckout($this->validPayload())->assertOk();

        $createRequests = array_values(array_filter(
            $this->stripeClient->requests,
            fn (array $request): bool => strtoupper($request['method']) === 'POST'
        ));
        $this->assertCount(2, $createRequests);
        $providerIdempotencyKeys = array_map(
            function (array $request): ?string {
                foreach ($request['headers'] as $header) {
                    if (preg_match('/^Idempotency-Key:\s*(.+)$/i', $header, $matches) === 1) {
                        return $matches[1];
                    }
                }

                return null;
            },
            $createRequests
        );
        $this->assertNotNull($providerIdempotencyKeys[0]);
        $this->assertSame(
            $providerIdempotencyKeys[0],
            $providerIdempotencyKeys[1]
        );
        $this->assertSame(1, Donation::query()->count());
    }

    public function test_stripe_configuration_missing_returns_503_without_creating_a_donation(): void
    {
        config(['services.stripe.secret_key' => null]);

        $this->postCheckout($this->validPayload())->assertStatus(503);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_stripe_failure_returns_502_without_provider_details(): void
    {
        $this->stripeClient->responseCode = 400;
        $this->stripeClient->responseBody = json_encode([
            'error' => [
                'message' => 'Invalid API Key provided',
                'type' => 'invalid_request_error',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->postCheckout($this->validPayload());

        $response->assertStatus(502)
            ->assertJson([
                'message' => 'Le service de paiement rencontre un problème. Veuillez réessayer plus tard.',
            ]);
        $this->assertSame('failed', Donation::query()->firstOrFail()->status);
        $this->assertStringNotContainsString('Invalid API Key', $response->getContent());
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

    private function postCheckout(array $payload, string $key = self::IDEMPOTENCY_KEY): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/donations/checkout-session', $payload);
    }

    public function test_repeated_checkout_for_completed_donation_returns_200_without_creating_another_stripe_session(): void
    {
        $this->postCheckout($this->validPayload())->assertCreated();

        $donation = Donation::query()->firstOrFail();
        $donation->update(['status' => 'completed']);

        $stripePostRequestsBeforeRetry = count(array_filter(
            $this->stripeClient->requests,
            fn (array $request): bool => strtoupper($request['method']) === 'POST'
        ));

        $response = $this->postCheckout($this->validPayload());

        $response->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('reference', $donation->internal_reference)
            ->assertJsonMissingPath('checkout_url');

        $stripePostRequestsAfterRetry = count(array_filter(
            $this->stripeClient->requests,
            fn (array $request): bool => strtoupper($request['method']) === 'POST'
        ));

        $this->assertSame(
            $stripePostRequestsBeforeRetry,
            $stripePostRequestsAfterRetry
        );

        $this->assertSame(1, Donation::query()->count());
    }
}

class FakeStripeHttpClient
{
    public array $lastParams = [];

    public array $requests = [];

    public string $responseBody;

    public int $responseCode = 200;

    public function __construct(array $defaultResponse = [])
    {
        $this->responseBody = json_encode($defaultResponse, JSON_THROW_ON_ERROR);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = null, $maxNetworkRetries = null): array
    {
        $this->lastParams = $params;
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        return [$this->responseBody, $this->responseCode, []];
    }

    
}