<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Receipt;
use App\Services\DonationReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DonationReceiptTest extends TestCase
{
    use RefreshDatabase;

    private DonationReceiptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'donation.receipt_number_prefix' => 'MNT-2026-',
            'donation.receipt.number_prefix' => 'MNT-2026-',
            'donation.organization.authorized_signatory' => 'Signataire confirmé',
        ]);

        $this->service = app(DonationReceiptService::class);
    }

    public function test_completed_donation_creates_a_private_pdf_receipt(): void
    {
        $donation = $this->completedDonation();

        $receipt = $this->service->generate($donation);

        $this->assertModelExists($receipt);
        $this->assertSame($donation->id, $receipt->donation_id);
        $this->assertSame('MNT-2026-0000001', $receipt->number);
        $this->assertNotNull($receipt->issued_at);
        $this->assertNotNull($donation->refresh()->receipt_issued_at);
        $this->assertStringStartsWith('receipts/', $receipt->file_path);
        $this->assertStringEndsWith('.pdf', $receipt->file_path);
        $this->assertStringNotContainsString('public', $receipt->file_path);
        $this->assertStringNotContainsString('stripe', $receipt->file_path);
        Storage::disk('local')->assertExists($receipt->file_path);
        $this->assertFileDoesNotExist(public_path($receipt->file_path));
    }

    public function test_pdf_hash_is_sha256_of_the_stored_file(): void
    {
        $receipt = $this->service->generate($this->completedDonation());
        $contents = Storage::disk('local')->get($receipt->file_path);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->file_hash);
        $this->assertSame(hash('sha256', $contents), $receipt->file_hash);
        $this->assertStringStartsWith('%PDF-', $contents);
    }

    public function test_receipt_pdf_view_contains_official_and_donor_information(): void
    {
        $donation = $this->completedDonation([
            'donor_address' => '24 rue de la Paix',
            'donor_city' => 'Montréal',
            'donor_province' => 'Québec',
            'donor_postal_code' => 'H1G 5Z7',
            'donor_country' => 'Canada',
        ]);
        $receipt = $this->service->generate($donation);

        $html = view('receipts.donation', [
            'donation' => $donation->refresh(),
            'receipt' => $receipt,
            'issuedAt' => $receipt->issued_at,
            'authorizedSignatory' => 'Signataire confirmé',
        ])->render();

        foreach ([
            "Reçu officiel de don aux fins de l'impôt sur le revenu",
            'Official donation receipt for income tax purposes',
            'MENER AUTREMENT INC',
            '86984 1601 RR0001',
            'Marie Kabasele',
            '24 rue de la Paix',
            'Montréal',
            '25.00',
            '25.00 CAD',
            $receipt->number,
            'Canada Revenue Agency',
            'Agence du revenu du Canada',
            'Signataire confirmé',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_pending_donation_does_not_create_a_receipt(): void
    {
        $donation = Donation::factory()->create(['status' => 'pending']);

        $this->expectException(RuntimeException::class);
        $this->service->generate($donation);

        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_failed_donation_does_not_create_a_receipt(): void
    {
        $donation = Donation::factory()->create(['status' => 'failed']);

        $this->expectException(RuntimeException::class);
        $this->service->generate($donation);

        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_missing_authorized_signatory_refuses_generation(): void
    {
        config(['donation.organization.authorized_signatory' => null]);
        $donation = $this->completedDonation();

        $this->expectExceptionMessage('signataire autorisé');
        $this->service->generate($donation);

        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_missing_prefix_refuses_generation(): void
    {
        config([
            'donation.receipt_number_prefix' => null,
            'donation.receipt.number_prefix' => null,
        ]);
        $donation = $this->completedDonation();

        $this->expectExceptionMessage('préfixe');
        $this->service->generate($donation);

        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_generation_is_idempotent_for_a_donation(): void
    {
        $donation = $this->completedDonation();

        $first = $this->service->generate($donation);
        $second = $this->service->generate($donation->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->number, $second->number);
        $this->assertDatabaseCount('receipts', 1);
        Storage::disk('local')->assertExists($first->file_path);
    }

    public function test_two_donations_receive_unique_sequential_numbers(): void
    {
        $first = $this->service->generate($this->completedDonation());
        $second = $this->service->generate($this->completedDonation([
            'donor_email' => 'paul@example.com',
            'internal_reference' => 'DON-SECOND-123',
        ]));

        $this->assertSame('MNT-2026-0000001', $first->number);
        $this->assertSame('MNT-2026-0000002', $second->number);
        $this->assertNotSame($first->number, $second->number);
    }

    private function completedDonation(array $attributes = []): Donation
    {
        return Donation::factory()->create(array_merge([
            'internal_reference' => 'DON-RECEIPT-123',
            'amount' => '25.00',
            'currency' => 'cad',
            'donor_name' => 'Marie Kabasele',
            'status' => 'completed',
            'advantage_value' => '0.00',
            'eligible_amount' => '25.00',
            'completed_at' => now(),
        ], $attributes));
    }
}
