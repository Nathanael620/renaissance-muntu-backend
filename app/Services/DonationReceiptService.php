<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DonationReceiptService
{
    public function generate(Donation $donation): Receipt
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($donation, &$storedPath): Receipt {
                $lockedDonation = Donation::query()
                    ->whereKey($donation->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedDonation || $lockedDonation->status !== 'completed') {
                    throw new RuntimeException('Un reçu ne peut être généré que pour un don confirmé.');
                }

                $existingReceipt = Receipt::query()
                    ->where('donation_id', $lockedDonation->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingReceipt) {
                    return $existingReceipt;
                }

                $authorizedSignatory = config('donation.organization.authorized_signatory');

                if (blank($authorizedSignatory)) {
                    throw new RuntimeException('Le signataire autorisé du reçu n’est pas configuré.');
                }

                $prefix = trim((string) config('donation.receipt_number_prefix', config('donation.receipt.number_prefix')));

                if ($prefix === '') {
                    throw new RuntimeException('Le préfixe des numéros de reçus n’est pas configuré.');
                }

                $receiptNumber = $this->nextReceiptNumber($prefix);
                $issuedAt = now();

                $receipt = Receipt::create([
                    'donation_id' => $lockedDonation->id,
                    'number' => $receiptNumber,
                    'issued_at' => $issuedAt,
                ]);

                $relativePath = sprintf(
                    'receipts/%s/%s/%s.pdf',
                    $issuedAt->format('Y'),
                    $issuedAt->format('m'),
                    Str::slug($lockedDonation->internal_reference)
                );
                $pdfContents = Pdf::loadView('receipts.donation', [
                    'donation' => $lockedDonation,
                    'receipt' => $receipt,
                    'issuedAt' => $issuedAt,
                    'authorizedSignatory' => $authorizedSignatory,
                ])->setPaper('a4');

                Storage::disk('local')->put($relativePath, $pdfContents->output());
                $storedPath = $relativePath;

                $fileContents = Storage::disk('local')->get($relativePath);
                $fileHash = hash('sha256', $fileContents);

                $receipt->update([
                    'file_path' => $relativePath,
                    'file_hash' => $fileHash,
                ]);

                $lockedDonation->update([
                    'receipt_issued_at' => $issuedAt,
                ]);

                return $receipt->fresh();
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function nextReceiptNumber(string $prefix): string
    {
        $sequence = DB::table('receipt_sequences')
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            DB::table('receipt_sequences')->insertOrIgnore([
                'prefix' => $prefix,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('receipt_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();
        }

        if (! $sequence) {
            throw new RuntimeException('La séquence de numérotation du reçu n’a pas pu être initialisée.');
        }

        $nextValue = (int) $sequence->last_value + 1;

        DB::table('receipt_sequences')
            ->where('id', $sequence->id)
            ->update([
                'last_value' => $nextValue,
                'updated_at' => now(),
            ]);

        return $prefix.str_pad((string) $nextValue, 7, '0', STR_PAD_LEFT);
    }
}
