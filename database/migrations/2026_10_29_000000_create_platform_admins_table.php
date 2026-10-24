<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SA-01: who may enter the Super Admin panel (/admin).
 *
 * A row here is the only thing that grants platform administration. It is created by an operator on the
 * server (php artisan platform-admin:grant), never through registration or a web form, and it is not tied to
 * a business, so it does not change what any business owner can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
