<?php

namespace App\Console\Commands;

use App\Models\ReceiptEmailOutbox;
use App\Services\DonationReceiptEmailDispatcher;
use Illuminate\Console\Command;

class RetryFailedDonationReceiptEmail extends Command
{
    protected $signature = 'receipts:retry-failed {outbox_id}';

    protected $description = 'Manually retry a failed donation receipt email';

    public function handle(DonationReceiptEmailDispatcher $dispatcher): int
    {
        $outboxId = filter_var($this->argument('outbox_id'), FILTER_VALIDATE_INT);
        $outbox = $outboxId === false ? null : ReceiptEmailOutbox::query()->find($outboxId);

        if (! $outbox || $outbox->status !== ReceiptEmailOutbox::STATUS_FAILED) {
            $this->error('A failed receipt outbox record with that ID was not found.');

            return self::FAILURE;
        }

        if (! $dispatcher->retryFailed((int) $outboxId)) {
            $this->error('The receipt could not be safely retried.');

            return self::FAILURE;
        }

        $outbox->refresh();
        $this->info("Receipt retry recorded with status [{$outbox->status}].");

        return in_array($outbox->status, [
            ReceiptEmailOutbox::STATUS_QUEUED,
            ReceiptEmailOutbox::STATUS_SENT,
        ], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
