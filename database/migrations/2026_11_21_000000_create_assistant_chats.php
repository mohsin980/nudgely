<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI WhatsApp assistant: switched on per business, with the owner's WhatsApp number for handover pings.
 * Each conversation the assistant handles is one assistant chat: it counts towards the plan's monthly AI
 * conversations, and ends when it hands over to a person (or a person takes over).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_profiles', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('organization_id');
            $table->string('owner_whatsapp', 20)->nullable()->after('languages');
        });

        Schema::create('assistant_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active'); // active, handed_over
            $table->unsignedSmallInteger('replies')->default(0);
            $table->string('language', 5)->nullable();
            $table->text('summary')->nullable();
            $table->json('details')->nullable(); // what the lead told the assistant: budget, area, timeline…
            $table->string('handover_reason', 255)->nullable();
            $table->timestampTz('qualified_at')->nullable();
            $table->timestampTz('handed_over_at')->nullable();
            $table->foreignId('taken_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_chats');

        Schema::table('assistant_profiles', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'owner_whatsapp']);
        });
    }
};
