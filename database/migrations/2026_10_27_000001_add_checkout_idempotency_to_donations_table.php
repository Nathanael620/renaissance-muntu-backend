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
        Schema::table('donations', function (Blueprint $table) {
            $table->string('checkout_idempotency_key', 64)->nullable()->unique()->after('internal_reference');
            $table->string('checkout_request_hash', 64)->nullable()->index()->after('checkout_idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropUnique(['checkout_idempotency_key']);
            $table->dropIndex(['checkout_request_hash']);
            $table->dropColumn(['checkout_idempotency_key', 'checkout_request_hash']);
        });
    }
};
