<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'price',
        'stock_total',
        'stock_reserved',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function getStockAvailableAttribute(): int
    {
        return max(0, $this->stock_total - $this->stock_reserved);
    }
}
