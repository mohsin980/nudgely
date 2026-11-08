<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a WhatsApp number was connected: pasted by hand ("manual") or with one click through QuoteFlow's
 * Meta app ("embedded_signup"), plus the two-step PIN QuoteFlow set when registering it (encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('connected_via', 20)->default('manual')->after('business_account_id');
            $table->text('registration_pin')->nullable()->after('app_secret');
            // Numbers connected with one click are signed with QuoteFlow's app secret, so they have none of their own.
            $table->text('app_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn(['connected_via', 'registration_pin']);
            $table->text('app_secret')->nullable(false)->change();
        });
    }
};
