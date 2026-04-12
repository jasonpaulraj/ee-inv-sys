<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Flash Sale</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #fafafa;
            color: #111827;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-brand svg {
            width: 24px;
            height: 24px;
        }

        .container {
            max-width: 600px;
            margin: 60px auto;
            padding: 0 20px;
            width: 100%;
        }

        .checkout-box {
            background: #fff;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #f3f4f6;
        }

        .timer-banner {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            color: #b45309;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .timer-banner .time-left {
            font-size: 1.25rem;
            font-variant-numeric: tabular-nums;
        }

        .timer-banner.urgent {
            background: #fef2f2;
            border-color: #fee2e2;
            color: #b91c1c;
        }

        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 12px;
        }

        .order-summary {
            margin-bottom: 32px;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .order-item-info {
            flex: 1;
        }

        .order-item-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .order-item-variant {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .order-item-price {
            font-weight: 600;
            font-size: 1rem;
        }

        .order-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.25rem;
            font-weight: 700;
            border-top: 1px solid #e5e7eb;
            padding-top: 16px;
            margin-top: 16px;
        }

        .actions {
            display: grid;
            gap: 12px;
        }

        .btn {
            width: 100%;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-primary {
            background: #000;
            color: #fff;
        }

        .btn-primary:hover:not(:disabled) {
            background: #374151;
        }

        .btn-secondary {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #f9fafb;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, .3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Success Overlay */
        .success-overlay {
            display: none;
            text-align: center;
            padding: 40px 0;
        }

        .success-overlay svg {
            width: 64px;
            height: 64px;
            color: #10b981;
            margin: 0 auto 20px;
        }

        .success-overlay h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .success-overlay p {
            color: #6b7280;
            margin-bottom: 32px;
        }

        .success-overlay .btn {
            width: auto;
            display: inline-block;
        }
    </style>
</head>

<body>

    <header class="header">
        <a href="/" style="text-decoration: none; color: inherit;">
    </header>

    <main class="container">
        <div class="checkout-box" id="checkout-form">
            <div class="timer-banner" id="timer-banner">
                <div>
                    <div>Stock reserved</div>
                    <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Complete your purchase before time
                        runs out</div>
                </div>
                <div class="time-left" id="timer">--:--</div>
            </div>

            <h2 class="section-title">Order Summary</h2>

            <div class="order-summary">
                <div class="order-item">
                    <div class="order-item-info">
                        <div class="order-item-name">{{ $reservation->variant->product->name }}</div>
                        <div class="order-item-variant">{{ $reservation->variant->name }}</div>
                    </div>
                    <div class="order-item-price">${{ number_format($reservation->variant->price, 2) }}</div>
                </div>

                <div class="order-total">
                    <span>Total</span>
                    <span>${{ number_format($reservation->variant->price, 2) }}</span>
                </div>
            </div>

            <div class="actions">
                <button class="btn btn-primary" id="btn-confirm" onclick="confirmPurchase()">Confirm Purchase - Pay
                    ${{ number_format($reservation->variant->price, 2) }}</button>
                <button class="btn btn-secondary" id="btn-cancel" onclick="cancelReservation()">Cancel</button>
            </div>
        </div>

        <div class="checkout-box success-overlay" id="success-screen">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h2>Payment Successful!</h2>
            <p>Your order for the {{ $reservation->variant->product->name }} has been confirmed and will be shipped
                soon.</p>
            <a href="/" class="btn btn-primary" style="text-decoration:none;">Return to Shop</a>
        </div>
    </main>

    <script>
        const reservationId = {{ $reservation->id }};
        const expiresAt = new Date("{{ $reservation->expires_at->toIso8601String() }}");
        const timerEl = document.getElementById('timer');
        const timerBanner = document.getElementById('timer-banner');
        const btnConfirm = document.getElementById('btn-confirm');
        const btnCancel = document.getElementById('btn-cancel');

        let timerInterval = setInterval(() => {
            const diff = Math.max(0, expiresAt - Date.now());
            const mins = Math.floor(diff / 60000);
            const secs = Math.floor((diff % 60000) / 1000);

            timerEl.textContent = `${mins}:${String(secs).padStart(2, '0')}`;

            if (diff < 30000) {
                timerBanner.classList.add('urgent');
            }

            if (diff === 0) {
                clearInterval(timerInterval);
                timerEl.textContent = '0:00';
                btnConfirm.disabled = true;
                timerBanner.innerHTML = '<div><strong>Reservation Expired</strong><br><span style="font-size:0.75rem;font-weight:400;">Your stock has been released.</span></div>';
                setTimeout(() => {
                    window.location.href = '/';
                }, 3000);
            }
        }, 1000);

        async function confirmPurchase() {
            btnConfirm.disabled = true;
            const originalText = btnConfirm.innerHTML;
            btnConfirm.innerHTML = '<div class="spinner"></div>';
            btnCancel.disabled = true;

            try {
                const r = await fetch('/api/confirm/' + reservationId, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                });

                if (r.ok) {
                    clearInterval(timerInterval);
                    document.getElementById('checkout-form').style.display = 'none';
                    document.getElementById('success-screen').style.display = 'block';
                } else {
                    const data = await r.json();
                    alert(data.message || 'Failed to confirm purchase.');
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = originalText;
                    btnCancel.disabled = false;
                }
            } catch (e) {
                alert('A network error occurred.');
                btnConfirm.disabled = false;
                btnConfirm.innerHTML = originalText;
                btnCancel.disabled = false;
            }
        }

        async function cancelReservation() {
            btnCancel.disabled = true;
            btnCancel.textContent = 'Cancelling...';
            btnConfirm.disabled = true;

            try {
                const r = await fetch('/api/cancel/' + reservationId, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                });

                if (r.ok) {
                    window.location.href = '/';
                } else {
                    alert('Failed to cancel reservation.');
                    btnCancel.disabled = false;
                    btnCancel.textContent = 'Cancel';
                    btnConfirm.disabled = false;
                }
            } catch (e) {
                alert('A network error occurred.');
                btnCancel.disabled = false;
                btnCancel.textContent = 'Cancel';
                btnConfirm.disabled = false;
            }
        }
    </script>

</body>

</html>