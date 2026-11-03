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
        if (Schema::hasTable('receipt_email_outbox')
            && ! Schema::hasColumn('receipt_email_outbox', 'dispatch_attempts')) {
            Schema::table('receipt_email_outbox', function (Blueprint $table): void {
                $table->unsignedTinyInteger('dispatch_attempts')->default(0)->after('attempts');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('receipt_email_outbox')
            && Schema::hasColumn('receipt_email_outbox', 'dispatch_attempts')) {
            Schema::table('receipt_email_outbox', function (Blueprint $table): void {
                $table->dropColumn('dispatch_attempts');
            });
        }
    }
};
