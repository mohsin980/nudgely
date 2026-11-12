<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currency a subscription is charged in (ISO 4217, upper case). Existing subscriptions were all in USD.
 * A business keeps this currency for life: the payment provider locks a customer to the currency of its first charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->char('currency', 3)->default('USD')->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
