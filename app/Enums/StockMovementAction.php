<?php

namespace App\Enums;

enum StockMovementAction: int
{
    case RESERVED = 1;
    case CANCELLED = 2;
    case CONFIRMED = 3;
    case EXPIRED = 4;

    public function label(): string
    {
        return match($this) {
            self::RESERVED => 'Reserved',
            self::CANCELLED => 'Cancelled',
            self::CONFIRMED => 'Confirmed',
            self::EXPIRED => 'Expired',
        };
    }
}
