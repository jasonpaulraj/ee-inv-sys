# Inventory Reservation System

Preventing overselling in high-concurrency flash sale systems.

---

## Setup

```bash
docker-compose up -d
docker-compose exec app php artisan migrate --seed
```

Open [http://localhost:8080](http://localhost:8080)

---

## API

| Method | Endpoint            | Description            |
| ------ | ------------------- | ---------------------- |
| `POST` | `/api/reserve/{id}` | Reserve inventory item |
| `POST` | `/api/confirm/{id}` | Confirm a reservation  |
| `POST` | `/api/cancel/{id}`  | Cancel a reservation   |

### Stock Formula

`Available = Total Stock − Confirmed Sales − Active Reservations`

Reservations in `active` or `confirmed` status both count against available stock. Expired and cancelled reservations release their hold.

---

## Reservation Lifecycle

```
reserve() → active (2-min TTL)
              ├── confirm() → confirmed  (permanent)
              ├── cancel()  → cancelled  (stock released)
              └── expiry    → expired    (stock released, via scheduler)
```

The `reservations:expire` command runs every minute via the Laravel scheduler and marks all `active` reservations past their `expires_at` timestamp as `expired`.

---

## Locking Strategy

### Why Two Locks?

The system uses a dual-lock approach to guarantee exactly one successful reservation under high concurrency:

**1. Redis Distributed Lock (`Cache::lock`)**

```php
Cache::lock("inventory:{$id}", 5)->block(3, function () { ... });
```

- Acquires a Redis lock with a 5-second TTL before any database operation.
- Only **one process** can hold the lock at a time — all others block for up to 3 seconds waiting.
- This serialises the entire reserve operation at the application layer, preventing hundreds of concurrent requests from reaching the database simultaneously (thundering-herd prevention).
- Chosen because Redis atomic operations (`SET NX PX`) are network-fast and do not require a database round-trip to acquire.

**2. MySQL Pessimistic Lock (`SELECT ... FOR UPDATE`)**

```php
DB::table('inventories')->where('id', $id)->lockForUpdate()->first();
```

- Inside the DB transaction, the inventory row is locked for the duration of the check-then-insert operation.
- Prevents any other transaction from reading the same row until the current one commits.
- Acts as a database-level safety net: even if the Redis lock is ever bypassed (e.g. clock skew, Redis restart), the DB transaction prevents a dirty read that could lead to overselling.

### Why Not Optimistic Locking?

Optimistic locking (version columns + retry on conflict) is appropriate for low-contention scenarios. In a flash sale with 500 simultaneous requests on 1 item, almost every request would fail the version check and retry, creating a retry storm that degrades performance without improving throughput. Pessimistic locking is the correct strategy here.

### Flow for 500 Concurrent Requests

```
500 requests arrive simultaneously
        ↓
Redis lock: 1 acquires, 499 block
        ↓
Lock holder: DB transaction → lockForUpdate → check stock → insert reservation
        ↓
Lock released
        ↓
Next waiter acquires lock → DB transaction → stock = 0 → 400 Out of stock
        ↓
... repeats until all 499 are rejected
```

**Result:** 1 successful reservation, 499 failures, zero overselling.

---

## Running Tests

```bash
docker-compose exec app php artisan test
```

Tests cover: reserve success, out-of-stock rejection, confirmed-stock consumption, confirm with expiry guard, cancel, expiry command, and sequential concurrency simulation.

---

## Load Test (Apache Bench)

```bash
ab -n 500 -c 100 -p /dev/null -T application/x-www-form-urlencoded \
   http://localhost:8080/api/reserve/1
```

Expected: exactly **1** row with `status = active`, 499 responses with HTTP 400.

---

## Expiry

```bash
php artisan reservations:expire
```

Scheduled to run every minute. Marks `active` reservations with `expires_at < now()` as `expired`, returning their stock to the available pool.
