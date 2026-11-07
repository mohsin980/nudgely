<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform administrator's settings for each AI model in the chain (config/ai.php "models"): its key (encrypted),
 * model name, address, whether it is used, and its place in the order. Anything left empty falls back to .env.
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
            $table->text('api_key')->nullable();
            $table->string('model', 150)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_settings');
    }
};
