<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_checkout_is_not_reported_as_paid(): void
    {
        Donation::factory()->create([
            'internal_reference' => 'DON-STATUS-PENDING',
            'stripe_session_id' => 'cs_test_statuspending',
            'status' => 'pending',
        ]);

        $this->getJson('/api/donations/status/cs_test_statuspending')
            ->assertOk()
            ->assertExactJson(['status' => 'pending']);
    }

    public function test_completed_checkout_is_reported_as_paid_only_after_server_confirmation(): void
    {
        Donation::factory()->create([
            'internal_reference' => 'DON-STATUS-COMPLETED',
            'stripe_session_id' => 'cs_test_statuscompleted',
            'status' => 'completed',
        ]);

        $this->getJson('/api/donations/status/cs_test_statuscompleted')
            ->assertOk()
            ->assertExactJson(['status' => 'completed']);
    }

    public function test_other_checkout_states_are_not_reported_as_paid(): void
    {
        Donation::factory()->create([
            'internal_reference' => 'DON-STATUS-FAILED',
            'stripe_session_id' => 'cs_test_statusfailed',
            'status' => 'failed',
        ]);

        $this->getJson('/api/donations/status/cs_test_statusfailed')
            ->assertOk()
            ->assertExactJson(['status' => 'not_completed']);
    }

    public function test_unknown_checkout_session_returns_not_found(): void
    {
        $this->getJson('/api/donations/status/cs_test_unknown')
            ->assertNotFound()
            ->assertJsonMissingPath('status');
    }

    public function test_malformed_checkout_session_is_not_accepted(): void
    {
        $this->getJson('/api/donations/status/not-a-stripe-session')
            ->assertNotFound();
    }
}
