<?php

namespace App\Console\Commands;

use App\Services\DonationReceiptEmailDispatcher;
use Illuminate\Console\Command;

class DispatchPendingReceiptEmails extends Command
{
    protected $signature = 'receipts:dispatch-pending {--limit=100}';

    protected $description = 'Dispatch pending donation receipt email outbox records';

    public function handle(DonationReceiptEmailDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $reconciled = $dispatcher->reconcileLegacyDonations($limit);
        $dispatched = $dispatcher->dispatchPending($limit);

        $this->info("Reconciled {$reconciled} legacy donation(s); dispatched {$dispatched} receipt email job(s).");

        return self::SUCCESS;
    }
}
