@extends('layouts.app')

@section('content')
    <form id="filter-form" action="{{ route('home') }}" method="GET"
        class="mb-10 flex flex-col md:flex-row md:items-center justify-between gap-4 transition-all">
        <div class="relative w-full md:w-96">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
            <input type="text" name="search" value="{{ $search }}" placeholder="Search products..."
                class="block w-full pl-10 pr-3 py-3 rounded-full bg-zinc-100 text-sm focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:bg-white transition-colors duration-200"
                onblur="this.form.submit()">
        </div>

        <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-zinc-500">Show</span>
            <select name="per_page" onchange="this.form.submit()"
                class="custom-select block w-20 pl-3 pr-8 py-2 text-sm bg-zinc-100 rounded-full focus:outline-none focus:ring-2 focus:ring-zinc-900 transition-colors cursor-pointer">
                <option value="10" {{ $perPage == 10 ? 'selected' : '' }}>10</option>
                <option value="25" {{ $perPage == 25 ? 'selected' : '' }}>25</option>
                <option value="50" {{ $perPage == 50 ? 'selected' : '' }}>50</option>
                <option value="100" {{ $perPage == 100 ? 'selected' : '' }}>100</option>
            </select>
        </div>
    </form>

    <div>
        @if($products->count() > 0)
            <ul class="flex flex-col gap-8">
                @foreach($products as $product)
                    <li class="flex flex-col md:flex-row md:items-start gap-4 md:gap-10">

                        <div class="flex-grow">
                            <h3 class="text-lg font-semibold text-zinc-900 mb-1">{{ $product->name }}</h3>
                            <p class="text-sm text-zinc-500 leading-relaxed max-w-2xl">{{ $product->description }}</p>
                        </div>

                        <div class="w-full md:w-56 flex-shrink-0 mt-2 md:mt-0">
                            <select
                                class="variant-select custom-select w-full bg-zinc-100 text-zinc-900 text-sm font-medium rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900 focus:bg-white transition-all cursor-pointer"
                                data-product-id="{{ $product->id }}" id="select-{{ $product->id }}">
                                @foreach($product->variants as $variant)
                                    <option value="{{ $variant->id }}" data-price="{{ $variant->price }}"
                                        data-stock="{{ $variant->stock_available }}">
                                        {{ $variant->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="w-full md:w-32 flex flex-col items-start md:items-end flex-shrink-0 mt-2 md:mt-0">
                            <div class="font-bold text-lg text-zinc-900" id="price-{{ $product->id }}">--</div>
                            <div class="text-xs font-medium mt-0.5 mb-3" id="stock-{{ $product->id }}"></div>

                            <button
                                class="btn-reserve bg-black hover:bg-zinc-800 text-white w-full md:w-auto px-5 py-2 rounded-lg text-sm font-medium transition-colors duration-200 disabled:bg-zinc-300 disabled:cursor-not-allowed"
                                id="btn-reserve-{{ $product->id }}" data-product-id="{{ $product->id }}">
                                Checkout
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="py-20 text-center">
                <h3 class="text-xl font-medium text-zinc-900 mb-2">No products found</h3>
                <p class="text-sm text-zinc-500">Try checking your spelling or using more general terms.</p>
            </div>
        @endif
    </div>

    <div class="mt-12">
        {{ $products->links() }}
    </div>
@endsection

@stack('scripts')
<script src="{{ asset('js/home.js') }}"></script>