<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'variants' => $this->whenLoaded('variants', function () {
                return $this->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => (float) $variant->price,
                    'stock' => $variant->stock_total,
                    'available' => $variant->stock_available,
                ]);
            }),
        ];
    }
}
