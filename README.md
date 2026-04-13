# Inventory Reservation System

## Architecture & Approach

This project is built with a focus on **foundational engineering rigor, scalability, and maintainability**. The architecture is designed to handle a highly concurrent, production-grade retail environment (e.g., Flash Sales) where overselling inventory is mathematically impossible.

### Key Decisions & Design Patterns

1. **Multi-Tiered Concurrency Defense (Edge Cases Handled):**
    - **Redis + Lua (First Line):** Atomically gates requests in-memory before they hit the database, deflecting invalid traffic instantly during massive spikes.
    - **Pessimistic Locking (Source of Truth):** Database-level `SELECT ... FOR UPDATE` locks serialize requests at the row level, ensuring race conditions never occur.
    - **Idempotency Keys:** Built-in network resilience; retried or duplicated client requests are caught early without double-deducting stock.
2. **Immutable Ledger Pattern:** `product_variants` provides a fast, transacting snapshot of current stock availability. Meanwhile, `stock_movements` acts as an append-only ledger for every status change (Reserve, Confirm, Cancel), preserving strict historical data for reporting.
3. **Clean Code & SOLID:** Business logic is stripped from controllers and moved into dedicated services (e.g., `ReservationService`). Controllers handle HTTP transport; services handle pure domain logic.
4. **Meaningful Error Handling:** Race conditions and logical errors throw highly specific custom Exceptions (e.g., `OutOfStockException`, `DuplicateReservationException`), which map cleanly to appropriate HTTP status codes.

### Tradeoffs & Assumptions

- **Assumption - Heavy Read/Write Asymmetry:** The system assumes read traffic (catalog browsing) vastly outweighs write traffic (purchasing). Therefore, Redis caching is heavily utilized for catalog views to prioritize fast reads, accepting a slower write path due to pessimistic database locking.
- **Tradeoff - Dual-Write Synchronicity over True Event Sourcing:** For simplicity and ease of review, the `stock_movements` ledger is written synchronously in the same database transaction as the locking update. In a true enterprise setup, this might be offloaded to an asynchronous Outbox Pattern or message bus (e.g., Kafka) to minimize transaction times, but that introduces infrastructural complexity that is overkill here.
- **Tradeoff - Cache Invalidation Strategy:** The system currently uses `Cache::forget('full_catalog')` upon any stock mutation. While functionally correct, at extreme scale this is an anti-pattern as it can trigger a "cache stampede" where thousands of concurrent users simultaneously hit the database to rebuild the catalog. A production-ready enhancement would utilize asynchronous cache warming or granular cache tags to update only the affected variant.

### Testing Strategy

- **PHPUnit (TDD):** In-memory SQLite tests validate core business logic and edge-case exception handling.
- **k6 Load Testing:** True network concurrency is verified via a Dockerized `grafana/k6` script, aggressively hammering the API with 500 simultaneous virtual users to confirm lock integrity.

### AI Workflow Disclosure

AI tools were used during the development of this project to accelerate velocity on non-critical parts of the codebase.

Specifically, AI assistance was utilized to generate structural boilerplate, draft initial documentation, scaffold basic Blade templates, and set up the foundation for the load testing script. 

However, all core architectural decisions and complex logic were human-driven. The concurrency implementation, database locking strategies, Lua caching, and the ledger system were deliberately architected and heavily refined by hand to ensure correct operation at scale. Any AI-generated code was treated as a rough first draft and strictly reviewed against SOLID principles and clean code standards before final implementation.

## Setup

### Docker

```bash
docker compose up -d
(Optional) docker-compose exec app php artisan migrate:fresh --seed
```

Open [http://localhost:8080](http://localhost:8080)

---

## Running Tests

### PHPUnit

Runs all unit and feature tests inside the `app` Docker container.

```bash
docker compose exec app ./vendor/bin/phpunit
```

Or with the testdox formatter:

```bash
docker compose exec app ./vendor/bin/phpunit --testdox
```

## Concurrency Load Testing

PHPUnit validates **correctness** of locking logic under rapid sequential calls.
For **true network-level concurrency** (500 simultaneous HTTP connections), the project ships a [k6](https://k6.io) script that runs inside Docker.

### Unified Test Runner

Runs PHPUnit **and** the k6 load test in one command, then automatically cleans up any reservations and resets `stock_reserved` back to its pre-test value:

```bash
./scripts/run-all-tests.sh
```

What it does:

1. **PHPUnit** — tests via `docker compose exec app`
2. **k6 load test** — 500 concurrent VUs via the `grafana/k6` Docker image on the same Docker network as the app
3. **DB cleanup** — deletes all reservations written by k6 and resets `stock_reserved` on affected variants

> **Prerequisite:** Ensure `product_variants` row with the target ID (default: `2`) exists and has sufficient `stock_total` before running.

### k6 Only (Docker)

```bash
docker compose --profile testing run --rm k6 run \
    -e BASE_URL=http://app:8000 \
    /k6/reserve.js
```

**Expected result for `stock_total = 13`, 500 VUs:**

```
✓ is status 201 (success — reserved)       ↳  2% — ✓ 13 / ✗ 487
✓ is status 400 (expected — out of stock)  ↳ 97% — ✓ 487 / ✗ 13
✓ no unexpected errors (not 409 or 5xx)   ↳ 100% — ✓ 500 / ✗ 0
```

## API Reference

All endpoints are under `/api`.

### `GET /api/products`

Returns the full product catalog with variant stock levels. Response is cached for 60 seconds and invalidated on any stock mutation.

### `GET /api/reservations/active`

Returns all currently active (non-expired) reservations.

### `POST /api/reserve/{variantId}`

Reserve a product variant.

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
