<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_pending_donation_with_required_fields(): void
    {
        $donation = Donation::factory()->create([
            'donor_name' => 'Marie Kabasele',
            'donor_email' => 'marie@example.com',
            'amount' => 100.00,
            'currency' => 'cad',
        ]);

        $this->assertModelExists($donation);
        $this->assertSame('pending', $donation->status);
        $this->assertDatabaseHas('donations', [
            'internal_reference' => $donation->internal_reference,
            'donor_name' => 'Marie Kabasele',
            'donor_email' => 'marie@example.com',
            'amount' => 100.00,
            'currency' => 'cad',
            'status' => 'pending',
        ]);
    }

    public function test_amount_is_stored_with_two_decimal_places(): void
    {
        $donation = Donation::factory()->create(['amount' => 99.5]);

        $this->assertSame('99.50', $donation->amount);
    }

    public function test_advantage_value_defaults_to_zero_in_the_database(): void
    {
        $donation = Donation::factory()->create();

        $this->assertDatabaseHas('donations', [
            'id' => $donation->id,
            'advantage_value' => 0,
        ]);
    }

    public function test_completed_donation_records_completion_timestamp(): void
    {
        $donation = Donation::factory()->completed()->create();

        $this->assertSame('completed', $donation->status);
        $this->assertNotNull($donation->completed_at);
    }

    public function test_internal_reference_must_be_unique(): void
    {
        $reference = 'DON-20260906-000001';
        Donation::factory()->create(['internal_reference' => $reference]);

        $this->expectException(QueryException::class);

        Donation::factory()->create(['internal_reference' => $reference]);
    }

    public function test_stripe_session_id_must_be_unique(): void
    {
        $sessionId = 'cs_test_unique_session';
        Donation::factory()->withStripeSession($sessionId)->create();

        $this->expectException(QueryException::class);

        Donation::factory()->withStripeSession($sessionId)->create();
    }

    public function test_stripe_identifiers_are_hidden_when_the_donation_is_serialized(): void
    {
        $donation = Donation::factory()->withStripeSession('cs_test_secret_session')->create();

        $serialized = $donation->toArray();

        $this->assertArrayNotHasKey('stripe_session_id', $serialized);

        // The value stays accessible on the model itself (internal use only).
        $this->assertSame('cs_test_secret_session', $donation->stripe_session_id);
    }
}
