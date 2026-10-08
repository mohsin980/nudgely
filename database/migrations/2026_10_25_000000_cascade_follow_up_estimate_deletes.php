<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A follow-up about a deleted estimate must not outlive it: with SET NULL it became a generic
 * "following up on your estimate" email. It is removed with its estimate instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->foreign('estimate_id')->references('id')->on('estimates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->foreign('estimate_id')->references('id')->on('estimates')->nullOnDelete();
        });
    }
};
