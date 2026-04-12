<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class TestController extends Controller
{
    public function index()
    {
        abort_unless(app()->environment('local'), 403, 'Destructive operation forbidden in production.');

        $variants = ProductVariant::with('product')->orderBy('product_id')->get();

        return view('test', compact('variants'));
    }

    public function variants(): JsonResponse
    {
        $variants = ProductVariant::with('product')
            ->orderBy('product_id')
            ->get()
            ->map(fn($v) => [
                'id' => $v->id,
                'product_name' => $v->product->name,
                'name' => $v->name,
                'price' => (float) $v->price,
                'stock' => $v->stock_total,
                'available' => $v->stock_available,
            ]);

        return response()->json($variants);
    }

    public function reset(): JsonResponse
    {
        abort_unless(app()->environment('local'), 403, 'Destructive operation forbidden in production.');

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Database reset and reseeded'
        ], 200);
    }
}
