<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Enums\StockMovementAction;
use App\Models\Reservation;
use App\Models\StockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredReservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Reservation $reservation)
    {
    }

    /**
     * Release an expired reservation and restore the variant's stock.
     *
     * The three writes (status update, stock decrement, stock movement) are wrapped
     * in a single transaction so a crash mid-way cannot leave stock_reserved
     * permanently decremented without the reservation being marked expired.
     *
     * A row-level lockForUpdate() re-fetches the reservation inside the transaction
     * to prevent a double-release race with the `reservations:expire` artisan command
     * — whichever process wins the lock will act; the other will see a non-ACTIVE
     * status and exit safely.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            $locked = Reservation::where('id', $this->reservation->id)
                ->where('status', ReservationStatus::ACTIVE)
                ->lockForUpdate()
                ->first();

            // Another process (artisan command or duplicate job) already handled this.
            if (!$locked) {
                return;
            }

            $locked->update(['status' => ReservationStatus::EXPIRED]);

            $locked->variant()->decrement('stock_reserved');

            StockMovement::create([
                'product_variant_id' => $locked->product_variant_id,
                'action' => StockMovementAction::EXPIRED,
                'quantity' => 1,
                'reference_type' => Reservation::class,
                'reference_id' => $locked->id,
            ]);

            Cache::forget('full_catalog');
        });
    }
}
