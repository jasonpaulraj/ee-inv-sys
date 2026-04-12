<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use App\Events\ReservationCreated;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\OutOfStockException;
use App\Exceptions\ReservationNotActionableException;
use App\Jobs\ReleaseExpiredReservationJob;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ReservationService
{
    /**
     * Atomically attempt to decrement the Redis stock counter using a Lua script.
     *
     * Using an atomic Lua script ensures the read-and-conditional-decrement is a
     * single operation — eliminating the race condition of separate EXISTS + DECR calls.
     *
     * Return values:
     *   -1 → key does not exist (cold-start / cache miss); fall through to DB-only path.
     *    0 → stock exhausted in Redis; fail fast without a DB round-trip.
     *   >0 → decremented successfully; caller must restore on DB failure.
     */
    private function tryDecrementRedis(string $redisKey): int
    {
        $lua = <<<'LUA'
            local v = redis.call('GET', KEYS[1])
            if not v then return -1 end
            local n = tonumber(v)
            if n <= 0 then return 0 end
            redis.call('DECR', KEYS[1])
            return n - 1
        LUA;

        try {
            return (int) Redis::eval($lua, 1, $redisKey);
        } catch (\Throwable) {
            // Redis is unavailable (e.g. test environment, cold container, network partition).
            // Treat as a cache miss: fall through to the authoritative DB layer.
            return -1;
        }
    }

    public function reserve(int $variantId, string $idempotencyKey, ?int $userId = null): Reservation
    {
        $redisKey = "stock:variant:{$variantId}";
        $redisWasDecremented = false;

        // ─── Layer 1: Redis optimistic pre-check (fast path) ───────────────────
        //
        // The Redis counter is a *performance optimisation*, not the correctness
        // guarantee.  It lets us short-circuit obvious out-of-stock requests before
        // they ever touch the database.  If Redis is unavailable or the key is
        // absent, we fall through to the authoritative DB layer below.

        $redisResult = $this->tryDecrementRedis($redisKey);

        if ($redisResult === 0) {
            // Redis confirms stock is exhausted — fail fast.
            throw new OutOfStockException();
        }

        if ($redisResult > 0) {
            // We successfully decremented the Redis counter.
            // We MUST restore it if the DB transaction below fails.
            $redisWasDecremented = true;
        }
        // $redisResult === -1 → key absent; proceed to DB layer without Redis pre-check.

        // ─── Layer 2: DB pessimistic lock (correctness guarantee) ──────────────
        //
        // A row-level exclusive lock (SELECT … FOR UPDATE) on the ProductVariant
        // row serialises all concurrent requests for the same variant.  Only one
        // transaction can hold the lock at a time, making it impossible for two
        // requests to both see stock > 0 and both successfully reserve the last unit.
        //
        // Why the lock is acquired FIRST:
        //   The idempotency check and the stock check both rely on data that must be
        //   stable while we read it.  Acquiring lockForUpdate() before those reads
        //   prevents the TOCTOU (time-of-check / time-of-use) window where a
        //   concurrent transaction could slip in between the check and the update.

        try {
            $reservation = DB::transaction(function () use ($variantId, $userId, $idempotencyKey) {
                // Step 1 — Acquire the exclusive row lock.
                $variant = ProductVariant::lockForUpdate()->findOrFail($variantId);

                // Step 2 — Idempotency check (safe under the lock).
                $existing = Reservation::where('idempotency_key', $idempotencyKey)
                    ->where('product_variant_id', $variantId)
                    ->where('status', ReservationStatus::ACTIVE)
                    ->first();

                if ($existing) {
                    throw new DuplicateReservationException();
                }

                // Step 3 — Authoritative stock check.
                if ($variant->stock_available <= 0) {
                    throw new OutOfStockException();
                }

                // Step 4 — Commit the reservation atomically inside the transaction.
                $variant->increment('stock_reserved');

                $expiresAt = now()->addMinutes(2);
                $reservation = Reservation::create([
                    'product_variant_id' => $variantId,
                    'user_id' => $userId,
                    'status' => ReservationStatus::ACTIVE,
                    'expires_at' => $expiresAt,
                    'idempotency_key' => $idempotencyKey,
                ]);

                StockMovement::create([
                    'product_variant_id' => $variantId,
                    'action' => StockMovementAction::RESERVED,
                    'quantity' => -1,
                    'reference_type' => Reservation::class,
                    'reference_id' => $reservation->id,
                ]);

                // Eagerly attach variant + product so ReservationResource can
                // serialise both without additional queries.
                $reservation->setRelation('variant', $variant->load('product'));

                ReservationCreated::dispatch($reservation);
                ReleaseExpiredReservationJob::dispatch($reservation)->delay($expiresAt);

                // Bust the full-catalog cache so available stock counts are fresh.
                Cache::forget('full_catalog');

                return $reservation;
            });
        } catch (\Throwable $e) {
            // If anything went wrong after Redis was decremented, restore the counter
            // so subsequent requests are not incorrectly blocked.
            if ($redisWasDecremented) {
                Redis::incr($redisKey);
            }
            throw $e;
        }

        return $reservation;
    }

    public function cancel(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $locked = Reservation::where('id', $reservation->id)
                ->where('status', ReservationStatus::ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new ReservationNotActionableException(
                    'Reservation cannot be cancelled — it is no longer active.'
                );
            }

            $locked->update(['status' => ReservationStatus::CANCELLED]);
            $locked->variant()->decrement('stock_reserved');

            StockMovement::create([
                'product_variant_id' => $locked->product_variant_id,
                'action' => StockMovementAction::CANCELLED,
                'quantity' => 1,
                'reference_type' => Reservation::class,
                'reference_id' => $locked->id,
            ]);

            // Restore the Redis counter so the freed slot is immediately visible
            // to the fast-path pre-check on the next incoming request.
            // Wrapped in try/catch: Redis unavailability must never prevent a cancel.
            try {
                $redisKey = "stock:variant:{$locked->product_variant_id}";
                if (Redis::exists($redisKey)) {
                    Redis::incr($redisKey);
                }
            } catch (\Throwable) {
                // Redis unavailable — the DB is the source of truth; safe to continue.
            }

            Cache::forget('full_catalog');
        });
    }

    public function confirm(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $locked = Reservation::where('id', $reservation->id)
                ->where('status', ReservationStatus::ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new ReservationNotActionableException(
                    'Reservation cannot be confirmed — it is no longer active.'
                );
            }

            $locked->update(['status' => ReservationStatus::CONFIRMED]);

            // Decrement stock_total (units available to sell) and stock_reserved
            // in a single chained call so both changes happen in this transaction.
            $locked->variant()->decrement('stock_total');
            $locked->variant()->decrement('stock_reserved');

            StockMovement::create([
                'product_variant_id' => $locked->product_variant_id,
                'action' => StockMovementAction::CONFIRMED,
                'quantity' => -1,
                'reference_type' => Reservation::class,
                'reference_id' => $locked->id,
            ]);

            Cache::forget('full_catalog');
        });
    }
}
