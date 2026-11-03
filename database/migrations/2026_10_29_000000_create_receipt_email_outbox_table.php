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
        Schema::create('receipt_email_outbox', function (Blueprint $table) {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_email_outbox');
    }
};
