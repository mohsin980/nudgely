<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook and Instagram Lead Ads: the Facebook Pages a business connected, and every lead received from them
 * (one row per Meta lead ID, so a lead delivered twice is imported once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_ads_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('page_id', 40)->unique();
            $table->string('page_name');
            $table->text('page_access_token'); // encrypted
            // The business's form tells people they'll be contacted on WhatsApp, so new leads count as opted in.
            $table->boolean('whatsapp_consent')->default(false);
            // New leads from this Page are handled by this team member (null: nobody yet).
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lead_ads_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_ads_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('leadgen_id', 40)->unique();
            $table->string('form_id', 40)->nullable();
            $table->string('form_name')->nullable();
            $table->string('ad_id', 40)->nullable();
            $table->string('ad_name')->nullable();
            $table->string('platform', 20)->nullable(); // fb or ig
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, imported, matched, failed
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_ads_leads');
        Schema::dropIfExists('lead_ads_pages');
    }
};
