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
        Schema::create('entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type');
            $table->string('foodics_id')->index();
            $table->integer('daftra_id');
            $table->json('metadata')->nullable();
            $table->string('status')->default('synced');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'foodics_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_mappings');
    }
};
