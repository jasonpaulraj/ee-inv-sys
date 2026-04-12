<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->variant;
        $product = $variant?->product;

        return [
            'reservation_id' => $this->id,
            'variant_name' => $variant?->name,
            'product_name' => $product?->name,
            'price' => (float) ($variant?->price ?? 0),
            'expires_at' => $this->expires_at,
        ];
    }
}
