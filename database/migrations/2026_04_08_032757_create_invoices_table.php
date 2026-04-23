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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('foodics_id')->index();
            $table->string('foodics_reference');
            $table->integer('daftra_id')->nullable();
            $table->string('status');
            $table->json('foodics_metadata')->nullable();
            $table->json('daftra_metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'foodics_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
