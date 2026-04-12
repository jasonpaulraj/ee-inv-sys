<?php

namespace App\Models;

use App\Enums\StockMovementAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = ['product_variant_id', 'action', 'quantity', 'reference_type', 'reference_id'];

    protected $casts = [
        'action' => StockMovementAction::class,
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
