# Inventory Reservation System

## Disclaimer for AI Usage

AI tools were used to generate non-critical areas of this project.

This includes:

- Documentation (README.md)
- Basic UI scaffolding (Blade templates and frontend JS)
- Load testing script (k6)
- Auxiliary scripts (Docker entrypoint, test runner)

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
