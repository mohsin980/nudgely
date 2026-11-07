<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI WhatsApp assistant knows about a business: a short profile (what it does, where, when, what to ask
 * new leads, when to bring in a person), its common questions and answers, and its catalog of products or services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('assistant_name', 60)->default('Assistant');
            $table->string('languages', 20)->default('en,ur');
            $table->text('about')->nullable();
            $table->string('service_area', 500)->nullable();
            $table->string('hours', 500)->nullable();
            $table->text('qualifying_questions')->nullable();
            $table->text('handover_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->text('answer');
            $table->timestamps();
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('category', 100)->nullable();
            $table->string('price', 100)->nullable();
            $table->text('details')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('assistant_faqs');
        Schema::dropIfExists('assistant_profiles');
    }
};
