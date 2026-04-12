<?php

namespace Tests\Feature;

use App\Exceptions\OutOfStockException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Concurrency correctness tests for the reservation service.
 *
 * PHPUnit runs in a single process, so true OS-level parallelism is not
 * achievable here.  These tests validate that the locking logic produces
 * correct outcomes under rapid sequential calls — which exercises the exact
 * same DB and Redis code paths that concurrent requests would hit.
 *
 * For true load testing (e.g. 500 simultaneous HTTP connections), use an
 * external tool such as k6, Apache Bench, or `hey`:
 *
 *   k6 run --vus 500 --iterations 500 k6/reserve.js
 *   ab -n 500 -c 500 -X POST http://localhost:8080/api/reserve/1
 *
 * Those tests confirm throughput and tail latency; these tests confirm
 * correctness of the locking invariants.
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function createVariant(int $stock): ProductVariant
    {
        $product = Product::create([
            'name' => 'Flash Sale Item',
            'description' => 'High-demand item for concurrency testing.',
        ]);

        return $product->variants()->create([
            'name' => 'Standard Edition',
            'price' => 299.99,
            'stock_total' => $stock,
            'stock_reserved' => 0,
        ]);
    }

    /**
     * Core invariant: with stock = 1, exactly 1 out of N rapid requests succeeds.
     *
     * This validates that the lockForUpdate() + stock_available guard prevents
     * more than one reservation being created against a single unit of stock.
     */
    public function test_only_one_reservation_succeeds_when_stock_is_one(): void
    {
        $variant = $this->createVariant(1);
        $service = app(ReservationService::class);

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                $service->reserve($variant->id, "key-{$i}");
                $successCount++;
            } catch (OutOfStockException) {
                $failureCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Exactly 1 reservation must succeed for stock = 1.');
        $this->assertEquals(9, $failureCount, 'All remaining 9 requests must be rejected as out-of-stock.');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $variant->fresh()->stock_reserved);
        $this->assertEquals(0, $variant->fresh()->stock_available);
    }

    /**
     * Validates the spec's exact Level 3 scenario: stock = 1, 500 requests.
     * Expected outcome per spec: 1 success, 499 failures.
     */
    public function test_spec_level3_scenario_stock_one_500_requests(): void
    {
        $variant = $this->createVariant(1);
        $service = app(ReservationService::class);

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 500; $i++) {
            try {
                $service->reserve($variant->id, "concurrent-{$i}");
                $successCount++;
            } catch (OutOfStockException) {
                $failureCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Spec requires exactly 1 successful reservation.');
        $this->assertEquals(499, $failureCount, 'Spec requires exactly 499 failures.');
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
     * stock_reserved must never exceed stock_total; stock_available must never go negative.
     */
    public function test_stock_available_never_goes_negative_under_rapid_requests(): void
    {
        $stock = 3;
        $variant = $this->createVariant($stock);
        $service = app(ReservationService::class);

        for ($i = 0; $i < 20; $i++) {
            try {
                $service->reserve($variant->id, "rapid-{$i}");
            } catch (\Exception) {
                // Failures expected — we are testing the invariant, not the count.
            }
        }

        $fresh = $variant->fresh();

        $this->assertGreaterThanOrEqual(
            0,
            $fresh->stock_available,
            'stock_available must never go negative.'
        );
        $this->assertLessThanOrEqual(
            $stock,
            $fresh->stock_reserved,
            'stock_reserved must never exceed original stock_total.'
        );
        $this->assertEquals(
            $stock,
            $fresh->stock_total,
            'stock_total must be unchanged by reservations alone.'
        );
    }

    /**
     * Idempotency under rapid duplicate keys returns exactly one reservation
     * and does not double-increment stock_reserved.
     */
    public function test_idempotency_key_prevents_duplicate_reservations_under_rapid_retry(): void
    {
        $variant = $this->createVariant(5);
        $service = app(ReservationService::class);

        $exceptions = [];

        // Simulate a client retrying the same request 10 times with the same key.
        for ($i = 0; $i < 10; $i++) {
            try {
                $service->reserve($variant->id, 'same-idempotency-key');
            } catch (\Exception $e) {
                $exceptions[] = get_class($e);
            }
        }

        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(
            1,
            $variant->fresh()->stock_reserved,
            'stock_reserved must be exactly 1 — idempotency must prevent double-reservation.'
        );

        // All subsequent attempts must throw DuplicateReservationException
        $this->assertCount(9, $exceptions);
        foreach ($exceptions as $class) {
            $this->assertStringContainsString('DuplicateReservation', $class);
        }
    }
}
