<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SA-06: platform audit rows can name the user an action was about. Not a foreign key, like organization_id:
 * the record outlives the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_user_id')->nullable()->after('organization_id');
            $table->index('subject_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_user_id']);
            $table->dropColumn('subject_user_id');
        });
    }
};
