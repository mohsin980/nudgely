<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account-based senders (Gmail, Microsoft, SMTP) keep their secret in `credentials`, encrypted by the model.
     * Existing domain connections get the defaults and keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->text('credentials')->nullable();
            $table->string('connection_status', 32)->default('active');
            $table->string('status_message', 255)->nullable();
            $table->timestampTz('last_tested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'connection_status', 'status_message', 'last_tested_at']);
        });
    }
};
