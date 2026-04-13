#!/bin/bash
set -e
echo "Running PHPUnit Tests"
docker compose exec app ./vendor/bin/phpunit
echo "Unit & Feature Tests Passed."
echo ""
echo "Running k6 Concurrency Load Test"
docker compose --profile testing run --rm k6 run \
    -e BASE_URL=http://app:8000 \
    /k6/reserve.js

echo ""
echo "Reverting k6 test data..."
docker compose exec app php artisan tinker --execute="
    \$reservations = \App\Models\Reservation::where('idempotency_key', 'LIKE', 'k6-%')->get();
    \$reservationIds = \$reservations->pluck('id');
    \$variantIds = \$reservations->pluck('product_variant_id')->unique();
    \$deletedMovements = \App\Models\StockMovement::where('reference_type', \App\Models\Reservation::class)->whereIn('reference_id', \$reservationIds)->delete();
    \$reservations->each->delete();
    \App\Models\ProductVariant::whereIn('id', \$variantIds)->update(['stock_reserved' => 0]);
    echo 'Cleaned up ' . \$reservations->count() . ' k6 reservation(s), ' . \$deletedMovements . ' stock movement(s), and reset stock_reserved for variant(s): ' . \$variantIds->join(', ') . PHP_EOL;
"
echo ""
echo "ALL TESTS COMPLETED!"
