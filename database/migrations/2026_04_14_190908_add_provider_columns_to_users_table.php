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
        Schema::table('users', function (Blueprint $table) {
            $table->string('foodics_id')->nullable()->after('foodics_ref');
            $table->json('daftra_meta')->nullable()->after('daftra_id');
            $table->json('foodics_meta')->nullable()->after('foodics_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['foodics_id', 'daftra_meta', 'foodics_meta']);
        });
    }
};
