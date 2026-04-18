<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\EntityMapping;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TruncateSyncCommand extends Command
{
    protected $signature = 'app:truncate-sync';

    protected $description = 'Truncate all sync tables: invoices, products, clients and entity_mappings';

    public function handle(): int
    {
        if (! $this->confirm('This will truncate invoices, products, clients and entity_mappings tables. Continue?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $models = [
            Invoice::class,
            Product::class,
            Client::class,
            EntityMapping::class,
        ];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($models as $model) {
                $table = (new $model)->getTable();
                $model::query()->truncate();
                $this->info("Truncated {$table}.");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('All sync tables truncated successfully.');

        return self::SUCCESS;
    }
}
