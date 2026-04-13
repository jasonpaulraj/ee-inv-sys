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

    public function handle(): void
    {
        $released = 0;

        Reservation::query()
            ->where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunk(500, function ($reservations) use (&$released) {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, &$released) {
                        $locked = Reservation::where('id', $reservation->id)
                            ->where('status', ReservationStatus::ACTIVE)
                            ->lockForUpdate()
                            ->first();

                        if (!$locked) {
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
