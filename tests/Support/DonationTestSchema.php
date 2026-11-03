<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DonationTestSchema
{
    public static function create(): void
    {
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('donations', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_reference', 40)->unique();
            $table->string('checkout_idempotency_key', 64)->nullable()->unique();
            $table->string('checkout_request_hash', 64)->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('donor_name');
            $table->string('donor_email');
            $table->index('donor_email');
            $table->string('donor_address')->nullable();
            $table->string('donor_city')->nullable();
            $table->string('donor_province')->nullable();
            $table->string('donor_postal_code', 20)->nullable();
            $table->string('donor_country')->nullable();
            $table->decimal('advantage_value', 10, 2)->default(0);
            $table->string('advantage_description')->nullable();
            $table->decimal('eligible_amount', 10, 2)->nullable();
            $table->timestamp('receipt_issued_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_event_id')->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->string('file_path', 500)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('email_queued_at')->nullable()->index();
            $table->timestamp('email_sent_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('receipt_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix')->unique();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('receipt_email_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('dispatch_attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->string('last_error_type')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['status', 'reserved_at']);
        });
    }
}
