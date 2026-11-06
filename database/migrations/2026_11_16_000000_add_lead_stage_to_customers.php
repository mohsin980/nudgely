<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead stages (New, Contacted, Quoted, Won, Lost). Existing customers start at the stage their
 * history already shows: an accepted estimate is Won, a sent one Quoted, any email Contacted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('lead_stage', 16)->default('new')->after('status');
            $table->timestampTz('lead_stage_changed_at')->nullable()->after('lead_stage');
            $table->index(['organization_id', 'lead_stage']);
        });

        $hasEstimate = fn (string $condition) => "exists (select 1 from estimates e where e.customer_id = customers.id and {$condition})";

        DB::table('customers')->whereRaw($hasEstimate("e.status = 'accepted'"))->update(['lead_stage' => 'won']);
        DB::table('customers')->where('lead_stage', 'new')->whereRaw($hasEstimate('e.sent_at is not null'))->update(['lead_stage' => 'quoted']);
        DB::table('customers')->where('lead_stage', 'new')
            ->whereRaw('exists (select 1 from messages m join conversations c on c.id = m.conversation_id where c.customer_id = customers.id)')
            ->update(['lead_stage' => 'contacted']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'lead_stage']);
            $table->dropColumn(['lead_stage', 'lead_stage_changed_at']);
        });
    }
};
