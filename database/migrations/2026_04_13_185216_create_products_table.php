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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('foodics_id')->index();
            $table->string('foodics_name')->default('Unknown Product');
            $table->string('foodics_sku')->nullable();
            $table->integer('daftra_id')->nullable();
            $table->string('status');
            $table->json('foodics_metadata')->nullable();
            $table->json('daftra_metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'foodics_id']);
            $table->index('foodics_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
