<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('workflow_status')->default('new')->index();
        });

        Schema::table('partnership_requests', function (Blueprint $table) {
            $table->string('review_status')->default('new')->index();
        });
    }

    public function down(): void
    {
        Schema::table('partnership_requests', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn('review_status');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['workflow_status']);
            $table->dropColumn('workflow_status');
        });
    }
};
