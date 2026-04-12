<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ReleaseExpiredReservationJob;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createVariant(int $stock = 1, float $price = 99.99): ProductVariant
    {
        $product = Product::create(['name' => 'Test Product', 'description' => null]);

        return $product->variants()->create([
            'name' => 'Default / Black',
            'price' => $price,
            'stock_total' => $stock,
            'stock_reserved' => 0,
        ]);
    }

    private function createReservation(
        ProductVariant $variant,
        ReservationStatus $status = ReservationStatus::ACTIVE,
        ?string $expiresAt = null,
        ?string $idempotencyKey = null
    ): Reservation {
        if ($status === ReservationStatus::ACTIVE) {
            $variant->increment('stock_reserved');
        }

        return Reservation::create([
            'product_variant_id' => $variant->id,
            'status' => $status,
            'expires_at' => $expiresAt ?? now()->addMinutes(2),
            'idempotency_key' => $idempotencyKey ?? 'test-session-key',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reservation — Happy Path
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reserve_success(): void
    {
        $variant = $this->createVariant(1);

        $response = $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'unique-123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'reservation_id',
                    'variant_name',
                    'product_name',
                    'price',
                    'expires_at',
                ]
            ]);

        // product_name must now be populated (variant->load('product') in the service)
        $response->assertJsonPath('data.product_name', 'Test Product');
        $response->assertJsonPath('data.variant_name', 'Default / Black');

        $this->assertDatabaseHas('reservations', [
            'product_variant_id' => $variant->id,
            'status' => ReservationStatus::ACTIVE->value,
        ]);
        $this->assertEquals(1, $variant->fresh()->stock_reserved);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reservation — Edge Cases & Guards
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reserve_fails_when_idempotency_matches(): void
    {
        $variant = $this->createVariant(2);

        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'duplicate-key',
        ])->assertStatus(201);

        // Same user hitting reload — must be rejected, not create a second reservation.
        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'duplicate-key',
        ])->assertStatus(409)->assertJson([
                    'success' => false,
                    'message' => 'You already have an active reservation for this item.',
                ]);

        // Only one reservation was ever created; stock_reserved never doubled.
        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $variant->fresh()->stock_reserved);
    }

    public function test_reserve_fails_when_out_of_stock(): void
    {
        $variant = $this->createVariant(1);
        $this->createReservation($variant, ReservationStatus::ACTIVE, null, 'user-xyz');

        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'user-abc',
        ])->assertStatus(400)->assertJson(['success' => false, 'message' => 'Item sold out.']);
    }

    public function test_reserve_returns_404_for_unknown_variant(): void
    {
        $this->postJson('/api/reserve/9999')->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Confirm
    // ─────────────────────────────────────────────────────────────────────────

    public function test_confirm_success(): void
    {
        $variant = $this->createVariant(5);
        $reservation = $this->createReservation($variant, ReservationStatus::ACTIVE, now()->addMinutes(2)->toDateTimeString());

        $this->postJson("/api/confirm/{$reservation->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Purchase confirmed']);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::CONFIRMED->value,
        ]);

        $variant->refresh();
        $this->assertEquals(4, $variant->stock_total);    // decremented on confirm
        $this->assertEquals(0, $variant->stock_reserved); // reservation hold released
    }

    public function test_confirm_fails_when_reservation_is_expired(): void
    {
        $variant = $this->createVariant(5);
        $reservation = $this->createReservation(
            $variant,
            ReservationStatus::ACTIVE,
            now()->subMinute()->toDateTimeString()
        );

        // Controller checks expires_at > now() → 404 because row is not found
        $this->postJson("/api/confirm/{$reservation->id}")
            ->assertStatus(404);

        // Status must remain ACTIVE — nothing was changed
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::ACTIVE->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cancel
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cancel_success(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation($variant);

        $this->postJson("/api/cancel/{$reservation->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Reservation cancelled']);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::CANCELLED->value,
        ]);
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
    }

    public function test_cancel_fails_when_reservation_is_already_confirmed(): void
    {
        $variant = $this->createVariant(5);
        $reservation = $this->createReservation($variant);
        $reservation->update(['status' => ReservationStatus::CONFIRMED]);
        $variant->decrement('stock_reserved'); // simulate confirm's side effect

        // Service throws ReservationNotActionableException → 422
        $this->postJson("/api/cancel/{$reservation->id}")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Reservation cannot be cancelled — it is no longer active.',
            ]);

        // Status must remain CONFIRMED — nothing was changed
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::CONFIRMED->value,
        ]);
    }

    public function test_cancel_fails_when_reservation_is_already_expired(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation($variant);
        $reservation->update(['status' => ReservationStatus::EXPIRED]);
        $variant->decrement('stock_reserved');

        $this->postJson("/api/cancel/{$reservation->id}")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Expiry — Artisan Command
    // ─────────────────────────────────────────────────────────────────────────

    public function test_expiry_command_marks_expired_reservations_and_restores_stock(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation(
            $variant,
            ReservationStatus::ACTIVE,
            now()->subMinute()->toDateTimeString()
        );

        $this->assertEquals(1, $variant->fresh()->stock_reserved);

        $this->artisan('reservations:expire');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::EXPIRED->value,
        ]);
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
    }

    public function test_expiry_command_is_idempotent_when_run_twice(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation(
            $variant,
            ReservationStatus::ACTIVE,
            now()->subMinute()->toDateTimeString()
        );

        $this->artisan('reservations:expire');
        $this->artisan('reservations:expire'); // second run must be a no-op

        $this->assertEquals(0, $variant->fresh()->stock_reserved);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::EXPIRED->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Expiry — Delayed Job (tests the job handler directly)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Queue is faked in setUp() so the job is never automatically dispatched.
     * We test the handler directly to cover the job code path independently
     * of the artisan command.
     */
    public function test_release_expired_reservation_job_marks_reservation_expired_and_restores_stock(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation(
            $variant,
            ReservationStatus::ACTIVE,
            now()->subMinute()->toDateTimeString()
        );

        $this->assertEquals(1, $variant->fresh()->stock_reserved);

        // Invoke the job handler directly (queue is faked; we test the logic, not the dispatch)
        (new ReleaseExpiredReservationJob($reservation))->handle();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::EXPIRED->value,
        ]);
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
    }

    public function test_release_expired_reservation_job_is_idempotent(): void
    {
        $variant = $this->createVariant();
        $reservation = $this->createReservation(
            $variant,
            ReservationStatus::ACTIVE,
            now()->subMinute()->toDateTimeString()
        );

        $job = new ReleaseExpiredReservationJob($reservation);

        // Run the job handler twice — simulates a job retry or overlap with the command.
        $job->handle();
        $job->handle();

        // stock_reserved must be 0 (not -1) — idempotent behaviour.
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::EXPIRED->value,
        ]);
    }
}
