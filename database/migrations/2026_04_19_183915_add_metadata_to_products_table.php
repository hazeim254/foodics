<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('foodics_name')->after('foodics_id')->default('Unknown Product');
            $table->string('foodics_sku')->nullable()->after('foodics_name');
            $table->json('foodics_metadata')->nullable()->after('status');
            $table->json('daftra_metadata')->nullable()->after('foodics_metadata');
            $table->foreignId('user_id')->after('id')->nullable()->change();
            $table->unsignedBigInteger('daftra_id')->nullable()->change();
            $table->index(['user_id', 'foodics_id']);
            $table->index('foodics_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'foodics_id']);
            $table->dropIndex(['foodics_sku']);
            $table->dropColumn(['foodics_name', 'foodics_sku', 'foodics_metadata', 'daftra_metadata']);
            $table->unsignedBigInteger('daftra_id')->nullable(false)->change();
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
