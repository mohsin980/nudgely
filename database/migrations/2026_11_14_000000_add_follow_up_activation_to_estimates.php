<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a business activated follow-ups for an imported estimate, and who did it. The estimate itself stays a draft:
 * activating follow-ups is not the same as sending the estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->timestamp('follow_up_activated_at')->nullable();
            $table->foreignId('follow_up_activated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_activated_by');
            $table->dropColumn('follow_up_activated_at');
        });
    }
};
