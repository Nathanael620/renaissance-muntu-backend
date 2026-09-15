<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();

            // Server-generated internal reference, never chosen by the client.
            $table->string('internal_reference', 40)->unique();

            // Payment amounts are stored and validated server-side.
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);

            // Donor identity (used for the official receipt).
            $table->string('donor_name');
            $table->string('donor_email');
            $table->index('donor_email');
            $table->string('donor_address')->nullable();
            $table->string('donor_city')->nullable();
            $table->string('donor_province')->nullable();
            $table->string('donor_postal_code', 20)->nullable();
            $table->string('donor_country')->nullable();

            // Advantage and official eligible amount (amount - advantage).
            $table->decimal('advantage_value', 10, 2)->default(0);
            $table->string('advantage_description')->nullable();
            $table->decimal('eligible_amount', 10, 2)->nullable();
            $table->timestamp('receipt_issued_at')->nullable();

            $table->string('status', 20)->default('pending');
            $table->index('status');

            // Stripe identifiers are immutable once a payment is confirmed.
            // Multiple NULL values are allowed, which suits pending donations.
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_event_id')->nullable()->unique();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
