<?php

namespace Tests\Feature;

use App\Jobs\SendDonationReceiptEmail;
use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\Receipt;
use App\Models\ReceiptEmailOutbox;
use App\Services\DonationReceiptEmailDispatcher;
use App\Services\DonationReceiptService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\DonationTestSchema;
use Tests\TestCase;

class DonationReceiptEmailOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DonationTestSchema::create();
    }

    public function test_dispatching_pending_receipt_marks_outbox_queued_and_dispatches_job(): void
    {
        Queue::fake([SendDonationReceiptEmail::class]);
        $donation = $this->completedDonation();
        $outbox = ReceiptEmailOutbox::query()->create([
            'donation_id' => $donation->id,
            'status' => ReceiptEmailOutbox::STATUS_PENDING,
        ]);

        $dispatched = app(DonationReceiptEmailDispatcher::class)->dispatchPending();

        $this->assertSame(1, $dispatched);
        $this->assertSame(ReceiptEmailOutbox::STATUS_QUEUED, $outbox->refresh()->status);
        $this->assertSame(1, $outbox->dispatch_attempts);
        Queue::assertPushed(SendDonationReceiptEmail::class, fn (SendDonationReceiptEmail $job): bool => $job->usesOutbox
            && $job->receiptId === $outbox->id);
    }

    public function test_manual_retry_resets_failed_outbox_and_queues_one_delivery(): void
    {
        Queue::fake([SendDonationReceiptEmail::class]);
        $donation = $this->completedDonation();
        $outbox = ReceiptEmailOutbox::query()->create([
            'donation_id' => $donation->id,
            'status' => ReceiptEmailOutbox::STATUS_FAILED,
            'attempts' => ReceiptEmailOutbox::MAX_DELIVERY_ATTEMPTS,
            'dispatch_attempts' => ReceiptEmailOutbox::MAX_DISPATCH_ATTEMPTS,
        ]);

        $retried = app(DonationReceiptEmailDispatcher::class)->retryFailed($outbox->id);

        $outbox->refresh();
        $this->assertTrue($retried);
        $this->assertSame(ReceiptEmailOutbox::STATUS_QUEUED, $outbox->status);
        $this->assertSame(0, $outbox->attempts);
        $this->assertSame(1, $outbox->dispatch_attempts);
        Queue::assertPushed(SendDonationReceiptEmail::class, fn (SendDonationReceiptEmail $job): bool => $job->receiptId === $outbox->id);
    }

    public function test_retry_command_succeeds_when_sync_queue_finishes_the_delivery(): void
    {
        Storage::fake('local');
        Mail::fake();
        $donation = $this->completedDonation();
        $path = 'receipts/synchronous-retry.pdf';
        Storage::disk('local')->put($path, 'fake PDF bytes');
        Receipt::query()->create([
            'donation_id' => $donation->id,
            'number' => 'TEST-0000004',
            'file_path' => $path,
            'issued_at' => now(),
        ]);
        $outbox = ReceiptEmailOutbox::query()->create([
            'donation_id' => $donation->id,
            'status' => ReceiptEmailOutbox::STATUS_FAILED,
        ]);

        $this->artisan('receipts:retry-failed', ['outbox_id' => $outbox->id])
            ->assertExitCode(0);

        $this->assertSame(ReceiptEmailOutbox::STATUS_SENT, $outbox->refresh()->status);
        Mail::assertSent(DonationReceiptMail::class);
    }

    public function test_completed_legacy_donation_without_receipt_is_reconciled_into_outbox(): void
    {
        $donation = $this->completedDonation();

        $reconciled = app(DonationReceiptEmailDispatcher::class)->reconcileLegacyDonations();

        $this->assertSame(1, $reconciled);
        $this->assertSame($donation->id, $donation->receiptEmailOutbox()->value('donation_id'));
    }

    public function test_ambiguous_legacy_queued_receipt_is_not_resent(): void
    {
        Queue::fake([SendDonationReceiptEmail::class]);
        $donation = $this->completedDonation();
        Receipt::query()->create([
            'donation_id' => $donation->id,
            'number' => 'TEST-0000001',
            'issued_at' => now(),
            'email_queued_at' => now(),
        ]);

        $dispatched = app(DonationReceiptEmailDispatcher::class)->dispatchForDonation($donation);

        $this->assertFalse($dispatched);
        $this->assertSame(0, ReceiptEmailOutbox::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_delivery_marks_outbox_sent_only_after_mail_is_accepted(): void
    {
        Storage::fake('local');
        Mail::fake();
        $donation = $this->completedDonation();
        $path = 'receipts/test-receipt.pdf';
        Storage::disk('local')->put($path, 'fake PDF bytes');
        $receipt = Receipt::query()->create([
            'donation_id' => $donation->id,
            'number' => 'TEST-0000002',
            'file_path' => $path,
            'issued_at' => now(),
        ]);
        $outbox = ReceiptEmailOutbox::query()->create([
            'donation_id' => $donation->id,
            'status' => ReceiptEmailOutbox::STATUS_QUEUED,
            'queued_at' => now(),
            'reserved_at' => now(),
        ]);

        (new SendDonationReceiptEmail($outbox->id, true))->handle(
            app(DonationReceiptService::class),
            app(DonationReceiptEmailDispatcher::class)
        );

        $this->assertSame(ReceiptEmailOutbox::STATUS_SENT, $outbox->refresh()->status);
        $this->assertNotNull($receipt->refresh()->email_sent_at);
        Mail::assertSent(DonationReceiptMail::class, fn (DonationReceiptMail $mail): bool => $mail->donation->is($donation)
            && $mail->receipt->is($receipt));
    }

    public function test_missing_pdf_schedules_bounded_retry_without_sending_mail(): void
    {
        Storage::fake('local');
        Mail::fake();
        $donation = $this->completedDonation();
        Receipt::query()->create([
            'donation_id' => $donation->id,
            'number' => 'TEST-0000003',
            'file_path' => 'receipts/missing.pdf',
            'issued_at' => now(),
        ]);
        $outbox = ReceiptEmailOutbox::query()->create([
            'donation_id' => $donation->id,
            'status' => ReceiptEmailOutbox::STATUS_QUEUED,
            'queued_at' => now(),
            'reserved_at' => now(),
        ]);

        try {
            (new SendDonationReceiptEmail($outbox->id, true))->handle(
                app(DonationReceiptService::class),
                app(DonationReceiptEmailDispatcher::class)
            );
            $this->fail('A missing receipt PDF should fail delivery.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Receipt email processing failed.', $exception->getMessage());
        }

        $outbox->refresh();
        $this->assertSame(ReceiptEmailOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame(RuntimeException::class, $outbox->last_error_type);
        Mail::assertNothingOutgoing();
    }

    private function completedDonation(): Donation
    {
        return Donation::factory()->create([
            'internal_reference' => 'DON-OUTBOX-123456',
            'status' => 'completed',
            'amount' => '25.00',
            'currency' => 'cad',
            'completed_at' => now(),
        ]);
    }
}
