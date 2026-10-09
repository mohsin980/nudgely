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
        Schema::table('email_connections', function (Blueprint $table) {
            $table->jsonb('dns_records')->nullable()->after('provider_domain_id');
            $table->string('verification_error')->nullable()->after('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->dropColumn(['dns_records', 'verification_error']);
        });
    }
};
