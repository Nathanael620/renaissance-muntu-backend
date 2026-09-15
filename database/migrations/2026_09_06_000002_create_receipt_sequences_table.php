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
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->id();

            // One row per receipt number prefix, used to allocate unique and
            // gap-less receipt numbers atomically (row-locked in a transaction).
            $table->string('prefix', 40)->unique();
            $table->unsignedBigInteger('last_value')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_sequences');
    }
};
