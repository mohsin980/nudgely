<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channels the AI assistant may reply on: whatsapp, email, or both. Null means WhatsApp only (the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_profiles', function (Blueprint $table) {
            $table->json('channels')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_profiles', function (Blueprint $table) {
            $table->dropColumn('channels');
        });
    }
};
