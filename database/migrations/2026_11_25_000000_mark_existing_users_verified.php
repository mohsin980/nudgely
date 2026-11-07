<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up now asks people to confirm their email address. Everyone who already has an account keeps
 * working without being asked: they are treated as confirmed from when they joined.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('coalesce(created_at, current_timestamp)')]);
    }

    public function down(): void
    {
        // Nothing to undo: which addresses were confirmed before this ran isn't recorded.
    }
};
