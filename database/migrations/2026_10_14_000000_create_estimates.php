<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates / quotes (Task 11). Money is numeric(12,2): never floating point.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Last estimate number handed out; incremented atomically (UPDATE … RETURNING).
            $table->unsignedInteger('estimate_sequence')->default(0);
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // NO ACTION (checked at the end of the statement): a customer with estimates can't be
            // deleted on its own, but deleting the organization removes both.
            $table->foreignId('customer_id')->constrained()->noActionOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            // EST-1024. A revision keeps the number and gets the next revision (EST-1024-R2).
            $table->string('estimate_number', 32);
            $table->unsignedSmallInteger('revision')->default(1);
            // The first version this one revises (null for an original).
            $table->foreignId('revision_of_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            // percent | fixed
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            // Percent, e.g. 8.250.
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->date('valid_until')->nullable();
            // Customer link: the token is kept encrypted (to rebuild the link) and looked up by its hash.
            $table->text('public_token')->nullable();
            $table->char('public_token_hash', 64)->nullable()->unique();
            // The email that carries the estimate; the estimate is "sent" only when it is delivered.
            $table->foreignId('send_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->string('decline_reason', 32)->nullable();
            $table->string('decline_note', 500)->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'estimate_number', 'revision']);
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'sent_at']);
            $table->index(['organization_id', 'accepted_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index('conversation_id');
            $table->index('revision_of_id');
        });

        // The database refuses impossible states, whatever the application does.
        DB::statement(<<<'SQL'
            alter table estimates
                add constraint estimates_status_check check (status in ('draft', 'sent', 'viewed', 'accepted', 'declined', 'expired', 'cancelled')),
                add constraint estimates_amounts_check check (subtotal >= 0 and discount_amount >= 0 and discount_amount <= subtotal and tax_amount >= 0 and total >= 0 and (tax_rate is null or tax_rate >= 0)),
                add constraint estimates_discount_check check (discount_type is null or discount_type in ('percent', 'fixed')),
                add constraint estimates_sent_check check (status in ('draft', 'cancelled') or sent_at is not null),
                add constraint estimates_viewed_check check (status <> 'viewed' or viewed_at is not null),
                add constraint estimates_accepted_check check ((status = 'accepted') = (accepted_at is not null)),
                add constraint estimates_declined_check check ((status = 'declined') = (declined_at is not null)),
                add constraint estimates_expired_check check (status <> 'expired' or expired_at is not null),
                add constraint estimates_cancelled_check check (status <> 'cancelled' or cancelled_at is not null)
        SQL);

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'sort_order']);
        });

        DB::statement('alter table estimate_items add constraint estimate_items_amounts_check check (quantity > 0 and unit_price >= 0 and amount >= 0)');

        // Estimate activity uses the existing activity log; an estimate may not have a conversation yet.
        Schema::table('conversation_events', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->change();
            $table->foreignId('estimate_id')->nullable()->after('customer_id')->constrained()->cascadeOnDelete();
            $table->index(['estimate_id', 'created_at']);
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('estimate_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            $table->index('estimate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropConstrainedForeignId('estimate_id'));
        DB::table('conversation_events')->whereNull('conversation_id')->delete();
        Schema::table('conversation_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimate_id');
            $table->foreignId('conversation_id')->nullable(false)->change();
        });
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('estimate_sequence'));
    }
};
