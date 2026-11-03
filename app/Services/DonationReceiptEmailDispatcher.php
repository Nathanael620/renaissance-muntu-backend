<?php

namespace App\Services;

use App\Jobs\SendDonationReceiptEmail;
use App\Models\Donation;
use App\Models\Receipt;
use App\Models\ReceiptEmailOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DonationReceiptEmailDispatcher
{
    private const QUEUED_LEASE_MINUTES = 15;

    private const SENDING_LEASE_MINUTES = 10;

    public function ensureForDonation(Donation $donation): ?ReceiptEmailOutbox
    {
        if ($donation->status !== 'completed' || $this->hasAmbiguousLegacyReceipt($donation)) {
            return null;
        }

        try {
            $outbox = ReceiptEmailOutbox::query()->firstOrCreate([
                'donation_id' => $donation->id,
            ]);
        } catch (QueryException $exception) {
            $outbox = ReceiptEmailOutbox::query()
                ->where('donation_id', $donation->id)
                ->first();

            if (! $outbox) {
                throw $exception;
            }
        }

        if ($donation->receipt()->whereNotNull('email_sent_at')->exists()
            && $outbox->status !== ReceiptEmailOutbox::STATUS_SENT) {
            $outbox->update([
                'status' => ReceiptEmailOutbox::STATUS_SENT,
                'available_at' => null,
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => null,
            ]);
        }

        return $outbox->refresh();
    }

    public function reconcileLegacyDonations(int $limit = 100): int
    {
        $donations = Donation::query()
            ->where('status', 'completed')
            ->whereDoesntHave('receiptEmailOutbox')
            ->whereDoesntHave('receipt', function (Builder $query): void {
                $query->whereNotNull('email_queued_at')
                    ->orWhereNotNull('email_sent_at');
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($donations as $donation) {
            $this->ensureForDonation($donation);
        }

        return $donations->count();
    }

    public function dispatchForDonation(Donation $donation): bool
    {
        $outbox = $this->ensureForDonation($donation);

        return $outbox !== null && $this->dispatchOutbox($outbox->id);
    }

    public function dispatchPending(int $limit = 100): int
    {
        $now = now();
        $queuedBefore = $now->copy()->subMinutes(self::QUEUED_LEASE_MINUTES);
        $sendingBefore = $now->copy()->subMinutes(self::SENDING_LEASE_MINUTES);

        $outboxIds = ReceiptEmailOutbox::query()
            ->whereHas('donation', function (Builder $query): void {
                $query->where('status', 'completed');
            })
            ->where(function (Builder $query) use ($now, $queuedBefore, $sendingBefore): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query->where('status', ReceiptEmailOutbox::STATUS_PENDING)
                        ->where(function (Builder $query) use ($now): void {
                            $query->whereNull('available_at')
                                ->orWhere('available_at', '<=', $now);
                        });
                })->orWhere(function (Builder $query) use ($queuedBefore): void {
                    $query->where('status', ReceiptEmailOutbox::STATUS_QUEUED)
                        ->where(function (Builder $query) use ($queuedBefore): void {
                            $query->whereNull('reserved_at')
                                ->orWhere('reserved_at', '<=', $queuedBefore);
                        });
                })->orWhere(function (Builder $query) use ($sendingBefore): void {
                    $query->where('status', ReceiptEmailOutbox::STATUS_SENDING)
                        ->where(function (Builder $query) use ($sendingBefore): void {
                            $query->whereNull('reserved_at')
                                ->orWhere('reserved_at', '<=', $sendingBefore);
                        });
                });
            })
            ->whereDoesntHave('donation.receipt', function (Builder $query): void {
                $query->whereNotNull('email_queued_at')
                    ->whereNull('email_sent_at');
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        $dispatched = 0;

        foreach ($outboxIds as $outboxId) {
            if ($this->dispatchOutbox((int) $outboxId)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function retryFailed(int $outboxId): bool
    {
        $canRetry = DB::transaction(function () use ($outboxId): bool {
            $outbox = ReceiptEmailOutbox::query()->lockForUpdate()->find($outboxId);

            if (! $outbox || $outbox->status !== ReceiptEmailOutbox::STATUS_FAILED) {
                return false;
            }

            $donation = Donation::query()->find($outbox->donation_id);

            if (! $donation
                || $donation->status !== 'completed'
                || $this->hasAmbiguousLegacyReceipt($donation)) {
                return false;
            }

            $outbox->update([
                'status' => ReceiptEmailOutbox::STATUS_PENDING,
                'attempts' => 0,
                'dispatch_attempts' => 0,
                'available_at' => now(),
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => null,
            ]);

            return true;
        });

        if (! $canRetry) {
            return false;
        }

        $this->dispatchOutbox($outboxId);

        return true;
    }

    private function dispatchOutbox(int $outboxId): bool
    {
        $reservedAt = now();
        $dispatchAttempt = DB::transaction(function () use ($outboxId, $reservedAt): ?int {
            $outbox = ReceiptEmailOutbox::query()->lockForUpdate()->find($outboxId);

            if (! $outbox || in_array($outbox->status, [
                ReceiptEmailOutbox::STATUS_SENT,
                ReceiptEmailOutbox::STATUS_FAILED,
            ], true)) {
                return null;
            }

            $donation = Donation::query()->find($outbox->donation_id);

            if (! $donation || $donation->status !== 'completed' || $this->hasAmbiguousLegacyReceipt($donation)) {
                return null;
            }

            if ($outbox->status === ReceiptEmailOutbox::STATUS_PENDING
                && $outbox->available_at !== null
                && $outbox->available_at->isFuture()) {
                return null;
            }

            if ($outbox->status === ReceiptEmailOutbox::STATUS_QUEUED
                && $outbox->reserved_at !== null
                && $outbox->reserved_at->gt($reservedAt->copy()->subMinutes(self::QUEUED_LEASE_MINUTES))) {
                return null;
            }

            if ($outbox->status === ReceiptEmailOutbox::STATUS_SENDING
                && $outbox->reserved_at !== null
                && $outbox->reserved_at->gt($reservedAt->copy()->subMinutes(self::SENDING_LEASE_MINUTES))) {
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

            if ($outbox->dispatch_attempts >= ReceiptEmailOutbox::MAX_DISPATCH_ATTEMPTS) {
                $outbox->update([
                    'status' => ReceiptEmailOutbox::STATUS_FAILED,
                    'available_at' => null,
                    'reserved_at' => null,
                    'claim_token' => null,
                ]);

                return null;
            }

            $dispatchAttempt = $outbox->dispatch_attempts + 1;
            $outbox->update([
                'status' => ReceiptEmailOutbox::STATUS_QUEUED,
                'dispatch_attempts' => $dispatchAttempt,
                'queued_at' => null,
                'reserved_at' => $reservedAt,
                'claim_token' => null,
            ]);

            return $dispatchAttempt;
        });

        if ($dispatchAttempt === null) {
            return false;
        }

        try {
            SendDonationReceiptEmail::dispatch($outboxId, true)->afterCommit();
        } catch (Throwable $exception) {
            $this->markDispatchFailure($outboxId, $reservedAt, $dispatchAttempt, $exception);

            return false;
        }

        ReceiptEmailOutbox::query()
            ->whereKey($outboxId)
            ->where('status', ReceiptEmailOutbox::STATUS_QUEUED)
            ->where('reserved_at', $reservedAt)
            ->update([
                'queued_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }

    private function markDispatchFailure(
        int $outboxId,
        Carbon $reservedAt,
        int $dispatchAttempt,
        Throwable $exception
    ): void {
        $backoff = [60, 300, 900, 3600];

        DB::transaction(function () use ($outboxId, $reservedAt, $dispatchAttempt, $exception, $backoff): void {
            $outbox = ReceiptEmailOutbox::query()
                ->lockForUpdate()
                ->whereKey($outboxId)
                ->where('status', ReceiptEmailOutbox::STATUS_QUEUED)
                ->where('reserved_at', $reservedAt)
                ->first();

            if (! $outbox) {
                return;
            }

            $exhausted = $dispatchAttempt >= ReceiptEmailOutbox::MAX_DISPATCH_ATTEMPTS;
            $delay = $backoff[min($dispatchAttempt - 1, count($backoff) - 1)];
            $outbox->update([
                'status' => $exhausted
                    ? ReceiptEmailOutbox::STATUS_FAILED
                    : ReceiptEmailOutbox::STATUS_PENDING,
                'available_at' => $exhausted ? null : now()->addSeconds($delay),
                'reserved_at' => null,
                'claim_token' => null,
                'last_error_type' => $exception::class,
            ]);
        });

        Log::error('La mise en file du reçu de donation a échoué.', [
            'outbox_id' => $outboxId,
            'dispatch_attempt' => $dispatchAttempt,
            'exception' => $exception::class,
        ]);
    }

    private function hasAmbiguousLegacyReceipt(Donation $donation): bool
    {
        return Receipt::query()
            ->where('donation_id', $donation->id)
            ->whereNotNull('email_queued_at')
            ->whereNull('email_sent_at')
            ->exists();
    }
}
