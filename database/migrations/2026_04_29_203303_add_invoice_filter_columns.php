<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('total_price', 10, 2)->nullable()->after('daftra_id');
            $table->string('daftra_no', 50)->nullable()->after('total_price');
        });

        DB::statement("
            UPDATE invoices
            SET total_price = CAST(json_extract(foodics_metadata, '$.total_price') AS REAL)
            WHERE foodics_metadata IS NOT NULL
        ");

        DB::statement("
            UPDATE invoices
            SET daftra_no = json_extract(daftra_metadata, '$.no')
            WHERE daftra_metadata IS NOT NULL
        ");

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('total_price');
            $table->index('daftra_no');
            $table->index(['status', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_total_price_index');
            $table->dropIndex('invoices_daftra_no_index');
            $table->dropIndex('invoices_status_created_at_index');
            $table->dropIndex('invoices_type_created_at_index');
            $table->dropColumn(['total_price', 'daftra_no']);
        });
    }
};
