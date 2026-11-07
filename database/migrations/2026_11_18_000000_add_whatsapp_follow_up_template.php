<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outside WhatsApp's 24-hour window, a business may only message a customer with a template Meta approved.
 * The business names its follow-up template here; QuoteFlow fills {{1}} with the customer's first name.
 *
 * Incoming WhatsApp messages are stored once: Meta's message id is unique among them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('follow_up_template', 512)->nullable()->after('webhook_verify_token');
            $table->string('follow_up_template_language', 15)->default('en')->after('follow_up_template');
        });

        PartialIndex::unique('messages', 'messages_inbound_whatsapp_unique', ['channel', 'provider_message_id'], "direction = 'inbound' and channel = 'whatsapp'");
    }

    public function down(): void
    {
        PartialIndex::drop('messages', 'messages_inbound_whatsapp_unique');

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn(['follow_up_template', 'follow_up_template_language']);
        });
    }
};
