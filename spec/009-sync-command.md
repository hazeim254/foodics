# Artisan Command: Sync Orders from Foodics to Daftra

## Context

A manual artisan command to sync orders from Foodics to Daftra for a specific user. This command is intended for **manual testing and debugging only**.

The command will:
1. Accept a `user_id` argument
2. Use `OrderService::fetchNewOrders()` to pull new orders from Foodics
3. Sync each order one-by-one using `SyncOrder::handle()`

### Dependencies

| Service | Responsibility |
|---------|---------------|
| `App\Services\Foodics\OrderService` | Fetches new orders from Foodics API (cursor-based pagination) |
| `App\Services\SyncOrder` | Orchestrates syncing a single order to Daftra |

### Key Requirement

Both `FoodicsApiClient` and `DaftraApiClient` resolve the current user from `Context::get('user')` (bound in `AppServiceProvider`). The command must set this context before calling any service.

---

## File to Create

### `app/Console/Commands/SyncOrdersCommand.php`

**Command signature:** `orders:sync {user_id}`

**Flow:**

1. Resolve the `User` by the given `user_id` argument (fail if not found)
2. Verify the user has a Foodics token (`$user->getFoodicsToken()`) — fail if missing
3. Set `Context::add('user', $user)` so the container resolves `FoodicsApiClient` and `DaftraApiClient` correctly
4. Instantiate `OrderService` from the container
5. Call `$orderService->fetchNewOrders()` to get all new orders
6. Output the number of orders found
7. Instantiate `SyncOrder` from the container (fresh instance per order to reset internal maps)
8. Loop through each order, call `$syncOrder->handle($order)` inside a try/catch
9. Log progress: order reference, success/failure per order
10. Output a summary at the end (synced count, skipped/failed count)

**Example output:**

```
Fetching new orders for user #5...
Found 12 order(s) to sync.

  [1/12] Syncing order ref-001... ✓
  [2/12] Syncing order ref-002... ✓
  [3/12] Syncing order ref-003... ✗ Already synced
  ...

Done. Synced: 10, Failed: 2.
```

---

## TODO List

- [x] Create `app/Console/Commands/SyncOrdersCommand.php` using `php artisan make:command`
- [x] Run `vendor/bin/pint --dirty`
- [ ] Manually test with `php artisan orders:sync 1`

---

## Notes

- **No tests required** — this command is strictly for manual testing purposes.
- Each order is synced independently; a failure on one order does not stop the rest.
- `SyncOrder::handle()` already has built-in duplicate detection (`skipIfAlreadySyncedLocally`), so re-running the command is safe.
