<?php

namespace App\Enums;

enum ReservationStatus: int
{
    case ACTIVE = 1;
    case CONFIRMED = 2;
    case CANCELLED = 3;
    case EXPIRED = 4;

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::CONFIRMED => 'Confirmed',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }
}
