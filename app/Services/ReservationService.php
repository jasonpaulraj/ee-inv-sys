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
            return -1;
        }
    }

    public function reserve(int $variantId, string $idempotencyKey, ?int $userId = null): Reservation
    {
        $redisKey = "stock:variant:{$variantId}";
        $redisWasDecremented = false;

        $redisResult = $this->tryDecrementRedis($redisKey);

        if ($redisResult === 0) {
            throw new OutOfStockException();
        }

        if ($redisResult > 0) {
            $redisWasDecremented = true;
        }

        try {
            $reservation = DB::transaction(function () use ($variantId, $userId, $idempotencyKey) {
                $variant = ProductVariant::lockForUpdate()->findOrFail($variantId);

                $existing = Reservation::where('idempotency_key', $idempotencyKey)
                    ->where('product_variant_id', $variantId)
                    ->where('status', ReservationStatus::ACTIVE)
                    ->first();

                if ($existing) {
                    throw new DuplicateReservationException();
                }

                if ($variant->stock_available <= 0) {
                    throw new OutOfStockException();
                }

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

                $reservation->setRelation('variant', $variant->load('product'));

                ReservationCreated::dispatch($reservation);
                ReleaseExpiredReservationJob::dispatch($reservation)->delay($expiresAt);

                Cache::forget('full_catalog');

                return $reservation;
            });
        } catch (\Throwable $e) {
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

            try {
                $redisKey = "stock:variant:{$locked->product_variant_id}";
                if (Redis::exists($redisKey)) {
                    Redis::incr($redisKey);
                }
            } catch (\Throwable) {
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
