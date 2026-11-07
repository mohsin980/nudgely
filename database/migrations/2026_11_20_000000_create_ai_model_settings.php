<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform administrator's settings for each AI model in the chain (config/ai.php "models"): model name, address,
 * whether it is used and its place in the order (anything left empty falls back to .env), and its API keys
 * (encrypted). A provider may have several keys: when one reaches its limit, the next one is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('model', 150)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_model_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->index();
            $table->string('label', 60)->nullable();
            $table->text('api_key');
            $table->char('fingerprint', 64); // sha256 of the key, to refuse the same key twice without decrypting
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_keys');
        Schema::dropIfExists('ai_model_settings');
    }
};
