<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReleaseExpiredReservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Reservation $reservation)
    {
    }

    public function handle(): void
    {
        if ($this->reservation->status === ReservationStatus::ACTIVE) {
            $this->reservation->update(['status' => ReservationStatus::EXPIRED]);
            
            // Atomically restore the stock
            $this->reservation->variant()->decrement('stock_reserved');

            \App\Models\StockMovement::create([
                'product_variant_id' => $this->reservation->product_variant_id,
                'action' => StockMovementAction::EXPIRED,
                'quantity' => 1,
                'reference_type' => Reservation::class,
                'reference_id' => $this->reservation->id,
            ]);
        }
    }
}
