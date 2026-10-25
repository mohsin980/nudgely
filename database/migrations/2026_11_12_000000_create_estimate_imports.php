<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSV estimate imports. An import is data entry: its rows become draft estimates and customers, and nothing is sent.
 *
 * Organization consistency is enforced by composite foreign keys (id, organization_id), so a row can only point at
 * records of its own business, on any database engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unique(['id', 'organization_id'], 'estimates_id_org_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['id', 'organization_id'], 'customers_id_org_unique');
        });

        Schema::create('estimate_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            // uploaded → ready → queued → processing → completed | failed
            $table->string('status', 20);
            $table->string('file_path', 255)->nullable();
            $table->char('file_sha256', 64);
            $table->json('column_map')->nullable();
            $table->json('summary')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'organization_id'], 'estimate_imports_id_org_unique');
            $table->index(['organization_id', 'status'], 'estimate_imports_org_status_index');
        });

        Schema::create('estimate_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('estimate_import_id');
            $table->unsignedInteger('row_number');
            // pending → imported | skipped | rejected
            $table->string('status', 20);
            $table->json('values');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedBigInteger('estimate_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();

            $table->foreign(['estimate_import_id', 'organization_id'], 'import_rows_import_fk')
                ->references(['id', 'organization_id'])->on('estimate_imports')->cascadeOnDelete();
            $table->foreign(['estimate_id', 'organization_id'], 'import_rows_estimate_fk')
                ->references(['id', 'organization_id'])->on('estimates');
            $table->foreign(['customer_id', 'organization_id'], 'import_rows_customer_fk')
                ->references(['id', 'organization_id'])->on('customers');
            $table->unique(['estimate_import_id', 'row_number'], 'import_rows_import_row_unique');
            $table->index(['estimate_import_id', 'status'], 'import_rows_import_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_import_rows');
        Schema::dropIfExists('estimate_imports');

        Schema::table('customers', fn (Blueprint $table) => $table->dropUnique('customers_id_org_unique'));
        Schema::table('estimates', fn (Blueprint $table) => $table->dropUnique('estimates_id_org_unique'));
    }
};
