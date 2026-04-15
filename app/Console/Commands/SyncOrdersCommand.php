<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Foodics\OrderService;
use App\Services\SyncOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;

class SyncOrdersCommand extends Command
{
    protected $signature = 'orders:sync {user_id}';

    protected $description = 'Sync orders from Foodics to Daftra for a given user';

    public function handle(): int
    {
        $user = User::find($this->argument('user_id'));

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        if (! $user->getFoodicsToken()) {
            $this->error('User does not have a Foodics token configured.');

            return self::FAILURE;
        }

        Context::add('user', $user);

        $this->info("Fetching new orders for user #{$user->id}...");

        $orders = app(OrderService::class)->fetchNewOrders();

        $total = count($orders);

        if ($total === 0) {
            $this->info('No new orders found.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} order(s) to sync.");
        $this->newLine();

        $synced = 0;
        $failed = 0;

        foreach ($orders as $i => $order) {
            $index = $i + 1;
            $reference = $order['reference'] ?? $order['id'];

            $this->output->write("  [{$index}/{$total}] Syncing order {$reference}... ");

            try {
                app(SyncOrder::class)->handle($order);
                $this->info('✓');
                $synced++;
            } catch (\Throwable $e) {
                throw $e;
                $this->warn('✗ '.$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Synced: {$synced}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
