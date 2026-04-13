<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');

        $query = Product::with('variants')->orderBy('name');

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%');
        }

        $products = $query->paginate($perPage)->appends($request->query());

        return view('home', compact('products', 'perPage', 'search'));
    }
}
