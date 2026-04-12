<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use App\Models\Reservation;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';
    protected $description = 'Safe sweep that expires overdue reservations and restores variant stock.';

    /**
     * Find and expire all ACTIVE reservations whose hold window has lapsed.
     *
     * Safety guarantees against concurrent execution (two overlapping cron runs):
     *
     *   1. chunk() processes large datasets without loading everything into memory.
     *   2. For every candidate row, a fresh DB::transaction() + lockForUpdate() is used
     *      to re-fetch the reservation with an exclusive row lock.  This means only one
     *      process can act on a given row — the other will see a non-ACTIVE status and
     *      skip it cleanly.
     *
     * This makes the command fully idempotent: running it concurrently or repeatedly
     * never double-decrements stock_reserved.
     */
    public function handle(): void
    {
        $released = 0;

        Reservation::query()
            ->where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunk(100, function ($reservations) use (&$released) {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, &$released) {
                        // Re-fetch under an exclusive row lock to prevent a race
                        // with ReleaseExpiredReservationJob or a sibling cron instance.
                        $locked = Reservation::where('id', $reservation->id)
                            ->where('status', ReservationStatus::ACTIVE)
                            ->lockForUpdate()
                            ->first();

                        if (!$locked) {
                            // Already handled — skip silently.
                            return;
                        }

                        $locked->update(['status' => ReservationStatus::EXPIRED]);

                        if ($locked->variant) {
                            $locked->variant->decrement('stock_reserved');

                            StockMovement::create([
                                'product_variant_id' => $locked->product_variant_id,
                                'action' => StockMovementAction::EXPIRED,
                                'quantity' => 1,
                                'reference_type' => Reservation::class,
                                'reference_id' => $locked->id,
                            ]);
                        }

                        $released++;
                    });
                }
            });

        if ($released > 0) {
            Cache::forget('full_catalog');
        }

        $this->info("Released {$released} expired reservation(s).");
    }
}
