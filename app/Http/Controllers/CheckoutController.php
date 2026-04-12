<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Models\Reservation;

class CheckoutController extends Controller
{
    public function show(Reservation $reservation)
    {
        if ($reservation->status !== ReservationStatus::ACTIVE || $reservation->expires_at < now()) {
            return response('Reservation expired or invalid.', 404);
        }

        return view('checkout', compact('reservation'));
    }
}
