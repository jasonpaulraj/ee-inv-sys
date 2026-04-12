<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
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

    private function createVariant(int $stock = 1, float $price = 99.99): ProductVariant
    {
        $product = Product::create(['name' => 'Test Product', 'description' => null]);

        return $product->variants()->create([
            'name'           => 'Default / Black',
            'price'          => $price,
            'stock_total'    => $stock,
            'stock_reserved' => 0,
        ]);
    }

    private function createReservation(ProductVariant $variant, ReservationStatus $status = ReservationStatus::ACTIVE, ?string $expiresAt = null, ?string $idempotencyKey = null): Reservation
    {
        if ($status === ReservationStatus::ACTIVE) {
            $variant->increment('stock_reserved');
        }

        return Reservation::create([
            'product_variant_id' => $variant->id,
            'status'          => $status,
            'expires_at'      => $expiresAt ?? now()->addMinutes(2),
            'idempotency_key' => $idempotencyKey ?? 'test-session-key',
        ]);
    }

    public function test_reserve_success(): void
    {
        $variant = $this->createVariant(1);

        $response = $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'unique-123'
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['reservation_id', 'variant_name', 'product_name', 'price', 'expires_at']]);

        $this->assertDatabaseHas('reservations', ['product_variant_id' => $variant->id, 'status' => ReservationStatus::ACTIVE->value]);
        $this->assertEquals(1, $variant->fresh()->stock_reserved);
    }

    public function test_reserve_fails_when_idempotency_matches(): void
    {
        $variant = $this->createVariant(2);

        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'duplicate-key'
        ])->assertStatus(201);

        // Same user hitting reload
        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'duplicate-key'
        ])->assertStatus(409)->assertJson(['message' => 'Session already has an active reservation for this item.']);
        
        $this->assertDatabaseCount('reservations', 1);
        $this->assertEquals(1, $variant->fresh()->stock_reserved);
    }

    public function test_reserve_fails_when_out_of_stock(): void
    {
        $variant = $this->createVariant(1);
        $this->createReservation($variant, ReservationStatus::ACTIVE, null, 'user-xyz');

        $this->postJson("/api/reserve/{$variant->id}", [], [
            'Idempotency-Key' => 'user-abc'
        ])->assertStatus(400)->assertJson(['message' => 'Out of stock']);
    }

    public function test_reserve_returns_404_for_unknown_variant(): void
    {
        $this->postJson('/api/reserve/9999')->assertStatus(404);
    }

    public function test_confirm_success(): void
    {
        $variant     = $this->createVariant(5);
        $reservation = $this->createReservation($variant, ReservationStatus::ACTIVE, now()->addMinutes(2)->toDateTimeString());

        $this->postJson("/api/confirm/{$reservation->id}")
             ->assertStatus(200)
             ->assertJson(['message' => 'Purchase confirmed']);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => ReservationStatus::CONFIRMED->value]);

        $variant->refresh();
        $this->assertEquals(4, $variant->stock_total);
        $this->assertEquals(0, $variant->stock_reserved); // Returns reserved stock down since it was fully bought
    }

    public function test_cancel_success(): void
    {
        $variant     = $this->createVariant();
        $reservation = $this->createReservation($variant);

        $this->postJson("/api/cancel/{$reservation->id}")
             ->assertStatus(200)
             ->assertJson(['message' => 'Reservation cancelled']);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => ReservationStatus::CANCELLED->value]);
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
    }

    public function test_expiry_command_marks_expired_reservations_and_restores_stock(): void
    {
        $variant     = $this->createVariant();
        $reservation = $this->createReservation($variant, ReservationStatus::ACTIVE, now()->subMinute()->toDateTimeString());

        $this->assertEquals(1, $variant->fresh()->stock_reserved);

        $this->artisan('reservations:expire');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => ReservationStatus::EXPIRED->value]);
        $this->assertEquals(0, $variant->fresh()->stock_reserved);
    }
}
