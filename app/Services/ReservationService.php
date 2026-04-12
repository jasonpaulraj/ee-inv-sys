<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use App\Events\ReservationCreated;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\OutOfStockException;
use App\Jobs\ReleaseExpiredReservationJob;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ReservationService
{
    public function reserve(int $variantId, string $idempotencyKey, ?int $userId = null): Reservation
    {
        $redisKey = "stock:variant:{$variantId}";
        if (Redis::exists($redisKey)) {
            $remaining = Redis::decr($redisKey);
            if ($remaining < 0) {
                Redis::incr($redisKey);
                throw new OutOfStockException();
            }
        }

        return DB::transaction(function () use ($variantId, $userId, $idempotencyKey) {
            $existing = Reservation::where('idempotency_key', $idempotencyKey)
                ->where('product_variant_id', $variantId)
                ->where('status', ReservationStatus::ACTIVE)
                ->first();

            if ($existing) {
                throw new DuplicateReservationException();
            }

            $variant = ProductVariant::lockForUpdate()->findOrFail($variantId);

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

            $reservation->setRelation('variant', $variant);

            ReservationCreated::dispatch($reservation);
            ReleaseExpiredReservationJob::dispatch($reservation)->delay($expiresAt);

            return $reservation;
        });
    }

    public function cancel(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $locked = Reservation::where('id', $reservation->id)
                ->where('status', ReservationStatus::ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
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

            $redisKey = "stock:variant:{$locked->product_variant_id}";
            if (Redis::exists($redisKey)) {
                Redis::incr($redisKey);
            }
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
                return;
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
        });
    }
}
