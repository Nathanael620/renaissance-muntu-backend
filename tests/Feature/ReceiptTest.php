<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Receipt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_receipt_for_an_existing_donation(): void
    {
        $donation = Donation::factory()->create();

        $receipt = Receipt::factory()->create(['donation_id' => $donation->id]);

        $this->assertModelExists($receipt);
        $this->assertNotNull($receipt->issued_at);
        $this->assertSame($donation->id, $receipt->donation_id);
    }

    public function test_receipt_is_linked_to_its_donation_and_vice_versa(): void
    {
        $donation = Donation::factory()->create();
        $receipt = Receipt::factory()->create(['donation_id' => $donation->id]);

        $this->assertSame($donation->id, $receipt->donation->id);
        $this->assertSame($receipt->id, $donation->receipt->id);
    }

    public function test_receipt_number_must_be_unique(): void
    {
        $donation = Donation::factory()->create();
        Receipt::factory()->create(['donation_id' => $donation->id, 'number' => 'SI2-0001317-0000001']);

        $this->expectException(QueryException::class);

        Receipt::factory()->create(['donation_id' => $donation->id, 'number' => 'SI2-0001317-0000001']);
    }

    public function test_one_receipt_cannot_be_issued_for_two_donations(): void
    {
        $donation = Donation::factory()->create();

        $this->expectException(QueryException::class);

        Receipt::factory()->create(['donation_id' => $donation->id]);
        Receipt::factory()->create(['donation_id' => $donation->id]);
    }
}
