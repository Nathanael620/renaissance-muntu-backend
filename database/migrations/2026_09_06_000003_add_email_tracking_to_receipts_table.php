<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->timestamp('email_queued_at')->nullable()->index();
            $table->timestamp('email_sent_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropColumn(['email_queued_at', 'email_sent_at']);
        });
    }
};
