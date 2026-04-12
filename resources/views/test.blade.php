@extends('layouts.app')

@section('title', 'Load Testing Lab')

@section('content')
    <div class="max-w-3xl mx-auto">
        <div class="mb-10">
            <h1 class="text-2xl font-bold text-zinc-900 flex items-center gap-3">
                <svg class="w-8 h-8 text-black" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                </svg>
                Concurrency Load Test
            </h1>
            <p class="text-zinc-500 mt-2 text-sm">Simulate heavy traffic across the system.</p>
        </div>

        <div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-zinc-900 mb-2">Target Variant</label>
                <select id="variant-select"
                    class="custom-select w-full bg-zinc-100 text-zinc-900 text-sm font-medium rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900 transition-all cursor-pointer">
                    @foreach($variants as $variant)
                        <option value="{{ $variant->id }}" data-stock="{{ $variant->stock_available }}">
                            {{ $variant->product->name }} — {{ $variant->name }} (Available: {{ $variant->stock_available }} /
                            Total: {{ $variant->stock_total }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-8">
                <label class="block text-sm font-medium text-zinc-900 mb-2">Concurrency Level (Total Requests)</label>
                <input type="number" id="num-requests" value="500" min="10" max="2000"
                    class="block w-full bg-zinc-100 text-zinc-900 text-sm font-medium rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900 transition-all">
            </div>

            <div class="flex flex-col sm:flex-row gap-4 mt-8">
                <button
                    class="bg-black hover:bg-zinc-800 text-white flex-1 px-5 py-3 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                    id="btn-start" onclick="runTest()">
                    Run Simulation
                </button>
                <button
                    class="bg-white border border-red-200 text-red-600 hover:bg-red-50 px-5 py-3 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                    id="btn-reset" onclick="resetDb()">
                    Reset DB
                </button>
            </div>

            <div id="progress-container" class="mt-8 hidden">
                <div class="flex justify-between items-center mb-2 text-sm font-medium text-zinc-900">
                    <span id="progress-text">Sending requests...</span>
                    <span id="progress-pct">0%</span>
                </div>
                <div class="w-full h-3 bg-zinc-100 rounded-full overflow-hidden">
                    <div id="progress-bar" class="h-full bg-black w-0 transition-all duration-100 ease-linear"></div>
                </div>
            </div>

            <div id="results" class="mt-12 pt-8 border-t border-zinc-100 hidden">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                    <div class="bg-zinc-50 p-6 rounded-2xl text-center">
                        <div class="text-3xl font-bold text-zinc-900 tabular-nums" id="stat-total">0</div>
                        <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mt-1">Sent</div>
                    </div>
                    <div class="bg-emerald-50 p-6 rounded-2xl text-center">
                        <div class="text-3xl font-bold text-emerald-600 tabular-nums" id="stat-success">0</div>
                        <div class="text-xs font-semibold text-emerald-700 uppercase tracking-wide mt-1">Successful</div>
                    </div>
                    <div class="bg-red-50 p-6 rounded-2xl text-center">
                        <div class="text-3xl font-bold text-red-600 tabular-nums" id="stat-fail">0</div>
                        <div class="text-xs font-semibold text-red-700 uppercase tracking-wide mt-1">Failed</div>
                    </div>
                </div>

                <label class="block text-sm font-medium text-zinc-900 mb-2">Execution Log</label>
                <div class="bg-zinc-900 text-emerald-400 p-4 rounded-xl font-mono text-xs h-48 overflow-y-auto"
                    id="log-box"></div>
            </div>
        </div>
    </div>
@endsection

@stack('scripts')
<script src="{{ asset('js/test.js') }}"></script>