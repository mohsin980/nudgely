<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One WhatsApp Business number per organization. The access token is encrypted by the model.
     */
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 64)->unique();
            $table->string('waba_id', 64);
            $table->string('display_phone_number', 32);
            $table->string('verified_name')->nullable();
            $table->text('access_token');
            $table->string('status', 16)->default('active');
            $table->timestampTz('connected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
