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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('email_connection_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('channel', 16);
            $table->string('provider', 32)->nullable();
            $table->string('from_address', 254);
            $table->string('from_name')->nullable();
            $table->string('to_address', 254);
            $table->string('to_name')->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->string('subject', 998);
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
