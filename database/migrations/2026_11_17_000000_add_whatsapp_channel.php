<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp as a second follow-up channel.
 *
 * - whatsapp_connections: one per business, the business's own WhatsApp Cloud API number. Secrets are encrypted.
 * - customers: preferred channel (null = business default), WhatsApp consent with when and how it was given,
 *   and the phone number in international (E.164) form, which WhatsApp needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 64);
            $table->string('business_account_id', 64);
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name', 191)->nullable();
            $table->text('access_token'); // encrypted
            $table->text('app_secret'); // encrypted
            $table->string('webhook_verify_token', 64);
            $table->string('status', 20)->default('pending'); // pending, active, failed
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique('phone_number_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_channel', 16)->nullable()->after('phone');
            $table->string('phone_e164', 20)->nullable()->after('phone_digits');
            $table->timestampTz('whatsapp_opt_in_at')->nullable()->after('preferred_channel');
            $table->string('whatsapp_opt_in_source', 20)->nullable()->after('whatsapp_opt_in_at');
            $table->index(['organization_id', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'phone_e164']);
            $table->dropColumn(['preferred_channel', 'phone_e164', 'whatsapp_opt_in_at', 'whatsapp_opt_in_source']);
        });

        Schema::dropIfExists('whatsapp_connections');
    }
};
