<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the connection was granted permission to read its own mailbox (for bounce notices).
        Schema::table('email_connections', function (Blueprint $table) {
            $table->boolean('bounce_scan_enabled')->default(false)->after('connection_status');
        });

        // One row per mailbox message examined, so a notice is never applied twice. No message content is kept.
        Schema::create('mailbox_bounce_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('provider_message_id', 255);
            $table->string('outcome', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['email_connection_id', 'provider_message_id'], 'mailbox_bounce_notices_connection_message_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_bounce_notices');
        Schema::table('email_connections', fn (Blueprint $table) => $table->dropColumn('bounce_scan_enabled'));
    }
};
