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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_type', 64);
            $table->string('external_event_id')->nullable();
            // Sanitized provider payload: no credentials, headers or attachment contents.
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // Providers retry deliveries; one event per provider message.
            $table->unique(['provider', 'event_type', 'external_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
