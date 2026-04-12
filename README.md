# Inventory Reservation System

> **Preventing overselling in high-concurrency flash sale systems.**

Built with **Laravel 12** · **PHP 8.2** · **Redis** · **MySQL** · **Laravel Horizon** · **Docker**

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Concurrency & Locking Strategy](#concurrency--locking-strategy)
3. [Reservation Lifecycle](#reservation-lifecycle)
4. [Stock Formula](#stock-formula)
5. [API Reference](#api-reference)
6. [Setup](#setup)
7. [Running Tests](#running-tests)
8. [Concurrency Load Testing](#concurrency-load-testing)

---

## System Overview

Flash sales cause extreme demand spikes where hundreds of users attempt to purchase the same limited-stock item simultaneously. Without proper concurrency control, multiple users can simultaneously read "stock > 0", all proceed to reserve, and all succeed — resulting in overselling.

This system solves that problem through a **dual-layer locking strategy** described in detail below.

---

## Concurrency & Locking Strategy

The reservation flow applies two complementary layers of protection:

```
POST /api/reserve/{variantId}
        │
        ▼
┌───────────────────────────────────────────┐
│  Layer 1: Redis Optimistic Pre-check      │  Fast path — avoids DB entirely
│  (Atomic Lua script: decrement if > 0)   │  when stock is obviously exhausted
└───────────────────┬───────────────────────┘
                    │
        ┌───────────┴──────────┐
        │ Redis result         │
        ├─ 0 (exhausted) ──────┼──► OutOfStockException (HTTP 400)
        ├─ > 0 (decremented) ──┼──► proceed to DB (restore on DB failure)
        └─ -1 (key absent) ────┼──► proceed to DB (cold-start / cache miss)
                    │
                    ▼
┌───────────────────────────────────────────┐
│  Layer 2: DB Pessimistic Lock             │  Correctness guarantee
│  DB::transaction() + lockForUpdate()     │
│                                           │
│  1. SELECT … FOR UPDATE on variant row   │  ◄─ exclusive row lock acquired
│  2. Idempotency check (under lock)       │  ◄─ safe: lock serialises readers
│  3. stock_available guard               │  ◄─ authoritative check
│  4. stock_reserved++                    │  ◄─ atomic within transaction
│  5. Reservation::create()               │
│  6. StockMovement::create()             │
│  7. Dispatch expiry job (TTL delay)     │
└───────────────────────────────────────────┘
```

### Why Pessimistic Over Optimistic Locking?

Flash sales create **expected high contention** on a small number of rows. With optimistic locking (version columns), the majority of concurrent requests would fail the version check and require retries — creating a thundering herd of retries under exactly the conditions where the system is already under maximum stress.

With pessimistic locking, requests queue behind the row lock. The lock is held for ~1–3ms per transaction. Under 500 concurrent requests, the 499 losers queue briefly, each gets the lock in turn, detects `stock_available = 0`, and returns a clean `OutOfStockException` — no retries needed.

### Why the Lock is Acquired BEFORE the Idempotency Check

A subtle but important ordering decision:

```php
// ❌ Wrong — TOCTOU window: two transactions can both see "no existing reservation"
$existing = Reservation::where('idempotency_key', $key)->first();
$variant  = ProductVariant::lockForUpdate()->findOrFail($variantId); // lock acquired too late

// ✅ Correct — lock first, then check under the lock
$variant  = ProductVariant::lockForUpdate()->findOrFail($variantId);
$existing = Reservation::where('idempotency_key', $key)->first();    // safe under lock
```

By acquiring the lock first, all concurrent requests for the same variant are fully serialised before any reads. The first transaction runs to completion; subsequent transactions see the state left by their predecessors.

### Redis as a Performance Optimisation (Not Correctness Layer)

The Redis counter provides a fast-path rejection for obviously out-of-stock requests — saving a database round-trip for the majority of failed attempts during a flash sale.

**It is not the correctness guarantee.** Redis can be:

- Unavailable (network partition, restart)
- Stale (key was never seeded, or was evicted)
- Temporarily ahead of or behind the DB due to a failed transaction rollback

Each of these cases is handled:

| Scenario                                   | Handling                                                      |
| ------------------------------------------ | ------------------------------------------------------------- |
| Key absent (cold-start)                    | `tryDecrementRedis()` returns `-1`; falls through to DB layer |
| Redis returns `0` (exhausted)              | Fail fast — `OutOfStockException` before DB round-trip        |
| DB transaction fails after Redis decrement | `catch(\Throwable)` restores the Redis counter with `incr()`  |
| Redis flushed / restarted                  | Only DB layer used; Redis repopulated on next stock seeding   |

### Atomic Lua Script

The Redis check uses a single atomic Lua script to read and conditionally decrement in one operation — eliminating the race condition of separate `EXISTS` + `DECR` calls:

```lua
local v = redis.call('GET', KEYS[1])
if not v then return -1 end      -- key absent
local n = tonumber(v)
if n <= 0 then return 0 end      -- out of stock
redis.call('DECR', KEYS[1])
return n - 1                     -- success: returns new value
```

---

## Reservation Lifecycle

```
                    ┌─────────┐
     POST /reserve  │  ACTIVE │  expires_at = now() + 2 minutes
     ──────────────►│         │
                    └────┬────┘
           ┌─────────────┼─────────────┐
           │             │             │
           ▼             ▼             ▼
    ┌──────────┐  ┌───────────┐  ┌─────────┐
    │CONFIRMED │  │ CANCELLED │  │ EXPIRED │
    └──────────┘  └───────────┘  └─────────┘
    POST /confirm  POST /cancel    (automatic)
```

State transition rules:

- **ACTIVE → CONFIRMED**: via `POST /api/confirm/{id}` — must be before `expires_at`
- **ACTIVE → CANCELLED**: via `POST /api/cancel/{id}` — releases stock immediately
- **ACTIVE → EXPIRED**: automatic after 2 minutes via Job or artisan command
- All transitions are **irreversible** — confirmed purchases cannot be reversed

### Dual Expiry Mechanism

Two independent mechanisms ensure expired reservations are released — providing defence-in-depth against queue downtime or failed jobs:

| Mechanism                             | Trigger                                         | Purpose                                       |
| ------------------------------------- | ----------------------------------------------- | --------------------------------------------- |
| `ReleaseExpiredReservationJob`        | Dispatched with 2-minute delay at creation time | Primary: precise, per-reservation             |
| `reservations:expire` artisan command | Scheduled (e.g. every minute via cron)          | Safety net: catches anything the queue missed |

Both are **idempotent**: they re-fetch with `lockForUpdate()` and check `status = ACTIVE` before acting, so a reservation is never double-released regardless of overlap.

---

## Stock Formula

```
Available Stock = stock_total − stock_reserved
```

| Column           | Meaning                           | Modified by                                    |
| ---------------- | --------------------------------- | ---------------------------------------------- |
| `stock_total`    | Units remaining to sell           | Decremented on CONFIRM                         |
| `stock_reserved` | Units held by ACTIVE reservations | +1 on RESERVE, −1 on CANCEL / EXPIRE / CONFIRM |

**Audit trail:** every mutation creates a `StockMovement` record with `action`, `quantity`, `reference_type`, and `reference_id`, allowing full historical reconstruction:

```sql
SELECT SUM(quantity) FROM stock_movements WHERE product_variant_id = ?;
-- Should equal (initial_stock_total - current_stock_total - current_stock_reserved)
```

---

## API Reference

All endpoints are under `/api`.

### `GET /api/products`

Returns the full product catalog with variant stock levels. Response is cached for 60 seconds and invalidated on any stock mutation.

### `GET /api/reservations/active`

Returns all currently active (non-expired) reservations.

### `POST /api/reserve/{variantId}`

Reserve a product variant.

**Headers:**

| Header            | Required | Description                                                                            |
| ----------------- | -------- | -------------------------------------------------------------------------------------- |
| `Idempotency-Key` | Optional | Prevents duplicate reservations for the same request. Use a UUID per checkout attempt. |

**Response codes:**

| Code  | Meaning                                                                    |
| ----- | -------------------------------------------------------------------------- |
| `201` | Reservation created                                                        |
| `400` | Out of stock                                                               |
| `404` | Variant not found                                                          |
| `409` | Duplicate reservation (same idempotency key + same variant already active) |

**201 Response:**

```json
{
    "data": {
        "reservation_id": 42,
        "variant_name": "256GB Silver",
        "product_name": "Apple iPhone 15 Pro",
        "price": 1299.99,
        "expires_at": "2026-04-12T15:02:00.000000Z"
    }
}
```

### `POST /api/confirm/{id}`

Mark a reservation as confirmed (purchase completed). Only works on ACTIVE reservations that have not expired.

| Code  | Meaning                                                                    |
| ----- | -------------------------------------------------------------------------- |
| `200` | Confirmed                                                                  |
| `404` | Not found, not active, or expired                                          |
| `422` | State changed between request and lock (race condition handled gracefully) |

### `POST /api/cancel/{id}`

Cancel an active reservation, immediately restoring stock.

| Code  | Meaning                                                         |
| ----- | --------------------------------------------------------------- |
| `200` | Cancelled                                                       |
| `404` | Reservation not found                                           |
| `422` | Cannot cancel — reservation is CONFIRMED, CANCELLED, or EXPIRED |

---

## Setup

### Docker (Recommended)

```bash
docker-compose up -d
docker-compose exec app php artisan migrate --seed
```

Open [http://localhost:8080](http://localhost:8080)

> **Queue connection:** Ensure `QUEUE_CONNECTION=redis` in your `.env` so Laravel Horizon (running in the `worker` container) processes the `ReleaseExpiredReservationJob`. If left as `database`, Horizon starts but processes nothing.

> **Redis client:** Set `REDIS_CLIENT=predis` in your `.env` to use the bundled `predis/predis` package. The `phpredis` extension must be separately installed if you prefer the native client.

### Local Development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer run dev   # starts server + queue worker + log watcher concurrently
```

---

## Running Tests

```bash
composer test
```

Or directly:

```bash
php artisan test --testdox
```

### Test Coverage Summary

| Test Class        | Tests | What is covered                                                                                                                              |
| ----------------- | ----- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `ReservationTest` | 11    | Happy path, idempotency, out-of-stock, confirm/cancel state machine, expiry command, expiry Job (direct handler invocation), Job idempotency |
| `ConcurrencyTest` | 4     | Stock=1+500 requests (spec Level 3), stock-never-negative invariant, idempotency under rapid retry                                           |

---

## Concurrency Load Testing

PHPUnit validates **correctness** of locking logic. For **throughput and tail latency** under true parallelism, use an external tool:

### k6 (recommended)

```bash
# Install: https://k6.io/docs/getting-started/installation/
k6 run --vus 500 --iterations 500 - <<'EOF'
import http from 'k6/http';
import { check } from 'k6';

export default function () {
  const res = http.post('http://localhost:8080/api/reserve/1', null, {
    headers: { 'Idempotency-Key': `key-${__VU}-${__ITER}` },
  });
  check(res, { 'status is 201 or 400': (r) => [201, 400].includes(r.status) });
}
EOF
```

**Expected result for stock = 1:** exactly 1 response with HTTP 201, 499 with HTTP 400.

### Apache Bench

```bash
ab -n 500 -c 500 \
   -m POST \
   -H "Idempotency-Key: bench-test" \
   http://localhost:8080/api/reserve/1
```
