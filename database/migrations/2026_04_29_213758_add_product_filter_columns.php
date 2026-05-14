<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('daftra_id');
        });

        $driver = DB::connection()->getDriverName();

        $priceSql = match ($driver) {
            'pgsql' => "UPDATE products SET price = CAST(foodics_metadata->>'price' AS NUMERIC(10,2)) WHERE foodics_metadata IS NOT NULL",
            'sqlite' => "UPDATE products SET price = CAST(json_extract(foodics_metadata, '$.price') AS REAL) WHERE foodics_metadata IS NOT NULL",
            default => "UPDATE products SET price = CAST(json_extract(foodics_metadata, '$.price') AS DECIMAL(10,2)) WHERE foodics_metadata IS NOT NULL",
        };

        DB::statement($priceSql);

        Schema::table('products', function (Blueprint $table) {
            $table->index('price');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['price']);
            $table->dropColumn('price');
        });
    }
};
