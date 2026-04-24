<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type')->default('invoice')->after('foodics_reference');
            $table->unsignedBigInteger('original_invoice_id')->nullable()->after('type');

            $table->foreign('original_invoice_id')
                ->references('id')
                ->on('invoices')
                ->onDelete('set null');

            $table->index('type');
            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['original_invoice_id']);
            $table->dropIndex(['user_id', 'type', 'status']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'original_invoice_id']);
        });
    }
};
