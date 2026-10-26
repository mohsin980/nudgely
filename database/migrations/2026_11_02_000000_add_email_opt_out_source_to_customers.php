<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // How the customer came to be opted out: "link" (their own unsubscribe) or "staff".
            $table->string('email_opted_out_source', 20)->nullable()->after('email_opted_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('email_opted_out_source'));
    }
};
