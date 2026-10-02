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
        Schema::create('email_reply_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->index()->constrained()->cascadeOnDelete();
            // SHA-256 of the reply token; the raw token is never stored.
            $table->char('token_hash', 64)->unique();
            $table->char('token_last4', 4);
            $table->boolean('active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_reply_routes');
    }
};
