<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\StockMovement;
use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';
    protected $description = 'Release expired reservations (Fallback Cleanup)';

    public function handle()
    {
        $expired = Reservation::where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            $reservation->update(['status' => ReservationStatus::EXPIRED]);

            if ($reservation->variant) {
                $reservation->variant->decrement('stock_reserved');
                
                StockMovement::create([
                    'product_variant_id' => $reservation->product_variant_id,
                    'action' => StockMovementAction::EXPIRED,
                    'quantity' => 1,
                    'reference_type' => Reservation::class,
                    'reference_id' => $reservation->id,
                ]);
            }
        }

        $this->info("Released {$expired->count()} expired reservations.");
    }
}
