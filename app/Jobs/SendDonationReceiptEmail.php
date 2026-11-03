<?php

namespace App\Jobs;

use App\Mail\DonationReceiptMail;
use App\Models\Donation;
use App\Models\Receipt;
use App\Models\ReceiptEmailOutbox;
use App\Services\DonationReceiptEmailDispatcher;
use App\Services\DonationReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendDonationReceiptEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * The default preserves jobs serialized before outbox delivery was introduced.
     */
    public function __construct(
        public int $receiptId,
        public bool $usesOutbox = false
    ) {}

    public function handle(
        DonationReceiptService $receiptService,
        DonationReceiptEmailDispatcher $receiptEmailDispatcher
    ): void {
        if (! $this->usesOutbox) {
            $receipt = Receipt::query()->with('donation')->find($this->receiptId);

            if ($receipt?->email_queued_at !== null && $receipt->email_sent_at === null) {
                Log::warning('Ancien job de reçu ignoré : la mise en file historique reste ambiguë.', [
                    'receipt_id' => $receipt->id,
                    'donation_id' => $receipt->donation_id,
                ]);

                return;
            }

            if ($receipt?->donation?->status === 'completed' && $receipt->email_sent_at === null) {
                $receiptEmailDispatcher->dispatchForDonation($receipt->donation);
            }

            return;
        }

        $claim = $this->claim();

        if ($claim === null) {
            return;
        }

        try {
            $donation = Donation::query()->find($claim['donation_id']);

            if (! $donation || $donation->status !== 'completed') {
                $this->markTerminal($claim['claim_token']);

                return;
            }

            $receipt = $donation->receipt()->first() ?? $receiptService->generate($donation);

            if ($receipt->email_sent_at !== null) {
                $this->markSent($claim['claim_token'], $receipt);

                return;
            }

            if (blank($receipt->file_path) || ! Storage::disk('local')->exists($receipt->file_path)) {
                throw new RuntimeException('Receipt PDF is unavailable.');
            }

            Mail::to($donation->donor_email)->send(
                new DonationReceiptMail($donation, $receipt)
            );

            $this->markSent($claim['claim_token'], $receipt);
        } catch (Throwable $exception) {
            try {
                $this->markRetryable($claim['claim_token'], $exception);
            } catch (Throwable $stateException) {
                Log::error('L’état de reprise du reçu n’a pas pu être enregistré.', [
                    'outbox_id' => $this->receiptId,
                    'exception' => $stateException::class,
                ]);
            }

            throw new RuntimeException('Receipt email processing failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Le job d’envoi du reçu a échoué.', [
            $this->usesOutbox ? 'outbox_id' : 'receipt_id' => $this->receiptId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    /**
     * @return array{donation_id: int, claim_token: string}|null
     */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $outbox = ReceiptEmailOutbox::query()->lockForUpdate()->find($this->receiptId);

            if (! $outbox || $outbox->status !== ReceiptEmailOutbox::STATUS_QUEUED) {
                return null;
            }

            $donation = Donation::query()->find($outbox->donation_id);

            if (! $donation || $donation->status !== 'completed') {
                $outbox->update([
                    'status' => ReceiptEmailOutbox::STATUS_FAILED,
                    'available_at' => null,
                    'reserved_at' => null,
                    'claim_token' => null,
                    'last_error_type' => 'DonationNotCompleted',
                ]);

                return null;
            }

            if ($outbox->attempts >= ReceiptEmailOutbox::MAX_DELIVERY_ATTEMPTS) {
                $outbox->update([
                    'status' => ReceiptEmailOutbox::STATUS_FAILED,
                    'available_at' => null,
                    'reserved_at' => null,
                    'claim_token' => null,
                ]);

                return null;
            }

            $hasAmbiguousLegacyReceipt = Receipt::query()
                ->where('donation_id', $donation->id)
                ->whereNotNull('email_queued_at')
                ->whereNull('email_sent_at')
                ->exists();

            if ($hasAmbiguousLegacyReceipt) {
                Log::warning('Reçu historique ambigu exclu de la reprise automatique.', [
                    'outbox_id' => $outbox->id,
                    'donation_id' => $donation->id,
                ]);

                return null;
            }

            $claimToken = (string) Str::uuid();
            $outbox->update([
                'status' => ReceiptEmailOutbox::STATUS_SENDING,
                'attempts' => $outbox->attempts + 1,
                'reserved_at' => now(),
                'claim_token' => $claimToken,
            ]);

            return [
                'donation_id' => $donation->id,
                'claim_token' => $claimToken,
            ];
        });
    }

    private function markSent(string $claimToken, Receipt $receipt): void
    {
        DB::transaction(function () use ($claimToken, $receipt): void {
            $outbox = ReceiptEmailOutbox::query()
                ->lockForUpdate()
                ->whereKey($this->receiptId)
                ->where('claim_token', $claimToken)
                ->first();

            if (! $outbox) {
                return;
            }

            Receipt::query()
                ->whereKey($receipt->id)
                ->whereNull('email_sent_at')
                ->update(['email_sent_at' => now()]);

            $outbox->update([
                'status' => ReceiptEmailOutbox::STATUS_SENT,
                'available_at' => null,
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => null,
            ]);
        });
    }

    private function markTerminal(string $claimToken): void
    {
        ReceiptEmailOutbox::query()
            ->whereKey($this->receiptId)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => ReceiptEmailOutbox::STATUS_FAILED,
                'available_at' => null,
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => 'DonationNotCompleted',
            ]);
    }

    private function markRetryable(string $claimToken, Throwable $exception): void
    {
        $backoff = [60, 300, 900, 3600];

        DB::transaction(function () use ($claimToken, $exception, $backoff): void {
            $outbox = ReceiptEmailOutbox::query()
                ->lockForUpdate()
                ->whereKey($this->receiptId)
                ->where('claim_token', $claimToken)
                ->first();

            if (! $outbox) {
                return;
            }

            $hasAttemptsLeft = $outbox->attempts < ReceiptEmailOutbox::MAX_DELIVERY_ATTEMPTS;
            $delay = $backoff[min($outbox->attempts - 1, count($backoff) - 1)];
            $outbox->update([
                'status' => $hasAttemptsLeft
                    ? ReceiptEmailOutbox::STATUS_PENDING
                    : ReceiptEmailOutbox::STATUS_FAILED,
                'available_at' => $hasAttemptsLeft ? now()->addSeconds($delay) : null,
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => $exception::class,
            ]);
        });

        Log::error('Échec d’une tentative d’envoi du reçu de donation.', [
            'outbox_id' => $this->receiptId,
            'exception' => $exception::class,
        ]);
    }
}
