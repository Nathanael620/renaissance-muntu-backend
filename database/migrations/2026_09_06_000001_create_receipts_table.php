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
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();

            // One official receipt per donation, never reused.
            $table->foreignId('donation_id')->unique()->constrained('donations')->restrictOnDelete();

            // Server-generated official receipt number, unique and immutable.
            $table->string('number', 40)->unique();

            // Reference to the stored PDF copy (private disk) and its SHA-256
            // checksum so an issued receipt can be verified and is never
            // silently modified.
            $table->string('file_path', 500)->nullable();
            $table->string('file_hash', 64)->nullable();

            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
