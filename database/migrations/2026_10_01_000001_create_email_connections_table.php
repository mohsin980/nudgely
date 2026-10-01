<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('domain', 253)->index();
            $table->string('sender_email', 254)->index();
            $table->string('sender_name');
            $table->string('provider_domain_id')->nullable();
            $table->string('verification_status', 32)->default('pending')->index();
            $table->boolean('is_default')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();
        });

        // Database-level guarantee of at most one default connection per organization.
        DB::statement(
            'CREATE UNIQUE INDEX email_connections_one_default_per_organization '
            .'ON email_connections (organization_id) WHERE is_default = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_connections');
    }
};
