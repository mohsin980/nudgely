<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Append-only history: every attempt (succeeded or failed) is its own row; nothing is overwritten.
        Schema::create('message_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            // One classification request (automatic or an explicit reclassify), shared by its retries.
            $table->string('request_id', 64);
            $table->string('status', 16);
            $table->string('intent', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('urgency', 16)->nullable();
            $table->boolean('requires_human_review')->default(false);
            $table->string('model', 100)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestampTz('classified_at')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'status']);
            $table->index(['organization_id', 'intent']);
        });

        // A request can succeed at most once, even if duplicate jobs race.
        DB::statement(
            'CREATE UNIQUE INDEX message_classifications_one_success_per_request '
            ."ON message_classifications (request_id) WHERE status = 'succeeded'"
        );

        Schema::table('conversations', function (Blueprint $table) {
            // Denormalized from the latest classified customer reply, for Inbox filters.
            $table->string('latest_intent', 32)->nullable()->after('status');
            $table->boolean('needs_attention')->default(false)->after('latest_intent');

            $table->index(['organization_id', 'needs_attention']);
            $table->index(['organization_id', 'latest_intent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'needs_attention']);
            $table->dropIndex(['organization_id', 'latest_intent']);
            $table->dropColumn(['latest_intent', 'needs_attention']);
        });

        Schema::dropIfExists('message_classifications');
    }
};
