<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ReservationResource;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $service)
    {
    }

    public function products(): AnonymousResourceCollection
    {
        $products = Cache::remember('full_catalog', 60, function () {
            return Product::with('variants')->orderBy('name')->get();
        });

        return ProductResource::collection($products);
    }

    public function activeReservations(): AnonymousResourceCollection
    {
        $reservations = Reservation::with('variant.product')
            ->where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        return ReservationResource::collection($reservations);
    }

    public function reserve(Request $request, int $variantId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? session()->getId();
        if (!$idempotencyKey) {
            $idempotencyKey = Str::random(40);
        }

        $reservation = $this->service->reserve(
            variantId: $variantId,
            idempotencyKey: $idempotencyKey,
            userId: auth()->id()
        );

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Confirm a reservation, completing the purchase.
     *
     * The controller performs an early 404 when the reservation is not found or
     * has already expired (expires_at check).  The service then re-checks under
     * a row-level lock to handle the narrow race window between this read and the
     * lock acquisition — returning 422 if the state changed in that interval.
     */
    public function confirm(int $id): JsonResponse
    {
        $reservation = Reservation::where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '>', now())
            ->findOrFail($id);

        $this->service->confirm($reservation);

        return response()->json([
            'success' => true,
            'message' => 'Purchase confirmed',
        ], 200);
    }

    /**
     * Cancel an active reservation, immediately restoring available stock.
     *
     * The status filter is intentionally omitted here so the controller always
     * finds the resource and returns a meaningful 422 (via ReservationNotActionableException)
     * rather than a generic 404 when the reservation exists but is not cancellable.
     */
    public function cancel(int $id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);

        $this->service->cancel($reservation);

        return response()->json([
            'success' => true,
            'message' => 'Reservation cancelled',
        ], 200);
    }
}
