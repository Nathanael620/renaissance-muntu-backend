<?php

namespace App\Jobs;

use App\Mail\DonationReceiptMail;
use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendDonationReceiptEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public int $receiptId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->receiptId;
    }

    public function handle(): void
    {
        $receipt = Receipt::query()->with('donation')->find($this->receiptId);

        if (! $receipt || ! $receipt->donation || $receipt->donation->status !== 'completed') {
            return;
        }

        if ($receipt->email_sent_at !== null) {
            return;
        }

        if (blank($receipt->file_path) || ! Storage::disk('local')->exists($receipt->file_path)) {
            Log::warning('Envoi du reçu ignoré : fichier PDF introuvable.', [
                'receipt_id' => $receipt->id,
                'donation_id' => $receipt->donation_id,
            ]);

            return;
        }

        Mail::to($receipt->donation->donor_email)->send(
            new DonationReceiptMail($receipt->donation, $receipt)
        );

        $receipt->update(['email_sent_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec définitif de l’envoi du reçu de donation.', [
            'receipt_id' => $this->receiptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
