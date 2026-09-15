<?php

namespace Tests\Feature;

use App\Jobs\SendDonationReceiptEmail;
use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\Receipt;
use App\Services\DonationReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Stripe\WebhookSignature;
use Tests\TestCase;

class DonationReceiptEmailTest extends TestCase
{
    use RefreshDatabase;

    private DonationReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        config([
            'donation.receipt_number_prefix' => 'MNT-2026-',
            'donation.receipt.number_prefix' => 'MNT-2026-',
            'donation.organization.authorized_signatory' => 'Signataire confirmé',
            'services.stripe.webhook_secret' => 'whsec_test_secret',
        ]);

        $this->receiptService = app(DonationReceiptService::class);
    }

    public function test_completed_donation_receipt_is_sent_to_the_donor(): void
    {
        $donation = $this->completedDonation();
        $receipt = $this->receiptService->generate($donation);

        (new SendDonationReceiptEmail($receipt->id))->handle();

        Mail::assertSent(DonationReceiptMail::class, function (DonationReceiptMail $mail) use ($donation, $receipt): bool {
            return $mail->hasTo($donation->donor_email)
                && $mail->receipt->id === $receipt->id;
        });
        $this->assertNotNull($receipt->refresh()->email_sent_at);
    }

    public function test_pending_donation_does_not_send_an_email(): void
    {
        $donation = Donation::factory()->create(['status' => 'pending']);
        $receipt = Receipt::factory()->create(['donation_id' => $donation->id]);

        (new SendDonationReceiptEmail($receipt->id))->handle();

        Mail::assertNothingSent();
        $this->assertNull($receipt->refresh()->email_sent_at);
    }

    public function test_failed_donation_does_not_send_an_email(): void
    {
        $donation = Donation::factory()->create(['status' => 'failed']);
        $receipt = Receipt::factory()->create(['donation_id' => $donation->id]);

        (new SendDonationReceiptEmail($receipt->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_missing_receipt_is_handled_without_sending(): void
    {
        $job = new SendDonationReceiptEmail(999999);

        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_missing_pdf_is_handled_without_sending(): void
    {
        $donation = $this->completedDonation();
        $receipt = Receipt::factory()->create([
            'donation_id' => $donation->id,
            'file_path' => 'receipts/missing.pdf',
        ]);

        (new SendDonationReceiptEmail($receipt->id))->handle();

        Mail::assertNothingSent();
        $this->assertNull($receipt->refresh()->email_sent_at);
    }

    public function test_mailable_has_the_private_pdf_attachment(): void
    {
        $donation = $this->completedDonation();
        $receipt = $this->receiptService->generate($donation);
        $mail = new DonationReceiptMail($donation, $receipt);
        $attachments = $mail->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame(basename($receipt->file_path), $attachments[0]->as ?? null);
    }

    public function test_mailable_content_is_bilingual_and_contains_the_payment_summary(): void
    {
        $donation = $this->completedDonation();
        $receipt = $this->receiptService->generate($donation);
        $html = (new DonationReceiptMail($donation, $receipt))->render();

        $this->assertStringContainsString('Paiement reçu / Payment received', $html);
        $this->assertStringContainsString('reçu officiel de don', $html);
        $this->assertStringContainsString('official donation receipt', $html);
        $this->assertStringContainsString($receipt->number, $html);
        $this->assertStringContainsString('25.00 CAD', $html);
        $this->assertStringContainsString($donation->created_at->format('d/m/Y'), $html);
    }

    public function test_job_is_configured_for_retries(): void
    {
        $job = new SendDonationReceiptEmail(123);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        $this->assertSame(120, $job->timeout);
    }

    public function test_smtp_failure_is_rethrown_for_retry_without_changing_completed_status(): void
    {
        $donation = $this->completedDonation();
        $receipt = $this->receiptService->generate($donation);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        Mail::swap($mailer);

        try {
            (new SendDonationReceiptEmail($receipt->id))->handle();
            $this->fail('The mail transport failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $this->assertSame('completed', $donation->refresh()->status);
        $this->assertNull($receipt->refresh()->email_sent_at);
    }

    public function test_repeated_webhook_dispatches_only_one_receipt_email_job(): void
    {
        Queue::fake();
        $donation = $this->completedDonation([
            'stripe_session_id' => 'cs_test_webhook_123',
        ]);
        $payload = $this->webhookPayload($donation);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($body, 'whsec_test_secret'),
        ];

        $this->call('POST', '/api/stripe/webhook', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/stripe/webhook', [], [], [], $headers, $body)->assertOk();

        Queue::assertPushed(SendDonationReceiptEmail::class, 1);
        $this->assertNotNull($donation->refresh()->receipt?->email_queued_at);
    }

    private function completedDonation(array $attributes = []): Donation
    {
        return Donation::factory()->create(array_merge([
            'internal_reference' => 'DON-EMAIL-123',
            'donor_name' => 'Marie Kabasele',
            'donor_email' => 'marie@example.com',
            'amount' => '25.00',
            'currency' => 'cad',
            'status' => 'completed',
            'advantage_value' => '0.00',
            'eligible_amount' => '25.00',
            'completed_at' => now(),
        ], $attributes));
    }

    private function webhookPayload(Donation $donation): array
    {
        return [
            'id' => 'evt_email_test_123',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $donation->stripe_session_id,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 2500,
                    'currency' => 'cad',
                    'client_reference_id' => $donation->internal_reference,
                    'metadata' => [
                        'donation_id' => (string) $donation->id,
                        'internal_reference' => $donation->internal_reference,
                    ],
                    'payment_intent' => 'pi_email_test_123',
                    'customer' => 'cus_email_test_123',
                ],
            ],
        ];
    }
}
