<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform outreach: the QuoteFlow team's own lead-generation messages. Contacts must have recorded consent before
 * anything is sent; templates are approved by a super admin before use; every send is logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('outreach_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_category_id')->nullable()->constrained('outreach_categories')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('email', 190)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique(); // E.164, for example +923001234567
            $table->string('consent_source', 190);
            $table->timestamp('consent_at')->nullable(); // null means no consent: MySQL would otherwise fill it with the current time
            $table->text('consent_note')->nullable();
            $table->timestamp('email_opted_out_at')->nullable();
            $table->timestamp('whatsapp_opted_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('outreach_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_category_id')->constrained('outreach_categories')->restrictOnDelete();
            $table->string('channel', 16); // email | whatsapp
            $table->string('name', 120);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('whatsapp_template_name', 120)->nullable(); // the name Meta approved
            $table->string('whatsapp_language', 16)->nullable();
            $table->string('status', 16)->default('draft'); // draft | pending | approved | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'channel']);
        });

        Schema::create('outreach_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_template_id')->constrained('outreach_templates')->restrictOnDelete();
            $table->foreignId('outreach_category_id')->constrained('outreach_categories')->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('queued'); // queued | running | completed
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        Schema::create('outreach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_campaign_id')->constrained('outreach_campaigns')->restrictOnDelete();
            $table->foreignId('outreach_contact_id')->nullable()->constrained('outreach_contacts')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('recipient', 190);
            $table->string('status', 16); // queued | sending | sent | failed | skipped
            $table->string('skip_reason', 120)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'created_at']);
            $table->index(['outreach_campaign_id', 'status']);
            $table->index(['outreach_contact_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_messages');
        Schema::dropIfExists('outreach_campaigns');
        Schema::dropIfExists('outreach_templates');
        Schema::dropIfExists('outreach_contacts');
        Schema::dropIfExists('outreach_categories');
    }
};
