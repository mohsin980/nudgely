<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer & conversation workspace (Task 10).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('phone', 32)->nullable()->after('email');
            // Digits only, for phone search.
            $table->string('phone_digits', 20)->nullable()->after('phone');
            $table->string('company', 255)->nullable()->after('phone_digits');
            $table->text('notes')->nullable()->after('company');
            $table->string('status', 16)->default('active')->after('notes');
            // Most recent message in any of the customer's conversations (kept by Conversation::recordActivity()).
            $table->timestampTz('last_activity_at')->nullable();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'last_activity_at']);
        });

        // Existing customers: split "John Smith" into first/last name; last activity from their conversations.
        $firstWord = DB::getDriverName() === 'pgsql' ? "split_part(trim(name), ' ', 1)" : "substring_index(trim(name), ' ', 1)";
        DB::statement(<<<SQL
            update customers set
                first_name = {$firstWord},
                last_name = nullif(trim(substr(trim(name), length({$firstWord}) + 1)), ''),
                last_activity_at = (select max(c.last_message_at) from conversations c where c.customer_id = customers.id)
        SQL);

        // One customer per email per organization (emails are stored lowercased and trimmed).
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'email']);
            $table->unique(['organization_id', 'email']);
        });

        // Search: one lowercased text column, indexed with trigrams for "contains" searches.
        DB::statement(<<<'SQL'
            alter table customers add column search_text text generated always as (
                lower(concat(coalesce(first_name, ''), ' ', coalesce(last_name, ''), ' ', name, ' ', email, ' ', coalesce(phone_digits, ''), ' ', coalesce(company, '')))
            ) stored
        SQL);

        // Trigram index for "contains" search: PostgreSQL only. MySQL scans one organization's rows instead.
        if (DB::getDriverName() === 'pgsql' && $this->trigramsAvailable()) {
            DB::statement('create index customers_search_text_trgm on customers using gin (search_text gin_trgm_ops)');
        }

        Schema::table('conversations', function (Blueprint $table) {
            // Copied from the latest classification so lists can filter/sort by priority in SQL.
            $table->decimal('latest_confidence', 4, 3)->nullable()->after('latest_intent');
            $table->string('latest_urgency', 16)->nullable()->after('latest_confidence');
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_reason', 32)->nullable();
            $table->index(['customer_id', 'last_message_at']);
        });

        DB::statement(<<<'SQL'
            update conversations set
                latest_confidence = (select mc.confidence from message_classifications mc
                    where mc.conversation_id = conversations.id and mc.status = 'succeeded' order by mc.id desc limit 1),
                latest_urgency = (select mc.urgency from message_classifications mc
                    where mc.conversation_id = conversations.id and mc.status = 'succeeded' order by mc.id desc limit 1)
        SQL);

        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('read_at')->nullable();
        });

        // Messages received before this feature count as read.
        DB::statement("update messages set read_at = coalesce(received_at, created_at) where direction = 'inbound'");
        PartialIndex::index('messages', 'messages_unread_index', ['conversation_id'], "direction = 'inbound' and read_at is null");

        Schema::table('message_classifications', function (Blueprint $table) {
            // "ai" (from the classifier) or "manual" (a person corrected the intent; the AI row is kept).
            $table->string('source', 16)->default('ai');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_intent', 32)->nullable();
            $table->string('override_reason', 255)->nullable();
        });

        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // closed, reopened, status_changed, classification_changed
            $table->string('type', 32);
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'status']);
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::dropIfExists('conversation_events');
        Schema::table('message_classifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['source', 'previous_intent', 'override_reason']);
        });
        PartialIndex::drop('messages', 'messages_unread_index');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('read_at'));
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'last_message_at']);
            $table->dropColumn(['latest_confidence', 'latest_urgency', 'closed_at', 'closed_reason']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists customers_search_text_trgm');
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('search_text');
            $table->dropUnique(['organization_id', 'email']);
            $table->index(['organization_id', 'email']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['organization_id', 'last_activity_at']);
            $table->dropColumn(['first_name', 'last_name', 'phone', 'phone_digits', 'company', 'notes', 'status', 'last_activity_at']);
        });
    }

    /**
     * pg_trgm makes "contains" searches use an index. Without it search still works (sequential
     * scan of one organization's rows), so a database that refuses the extension is not fatal.
     */
    private function trigramsAvailable(): bool
    {
        try {
            // Savepoint: a refused extension must not abort the migration's transaction.
            DB::transaction(fn () => DB::statement('create extension if not exists pg_trgm'));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
