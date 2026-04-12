<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->id,
            'variant_name' => $this->variant->name ?? null,
            'product_name' => $this->variant->product->name ?? null,
            'price' => (float) ($this->variant->price ?? 0),
            'expires_at' => $this->expires_at,
        ];
    }
}
