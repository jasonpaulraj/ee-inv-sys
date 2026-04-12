document.addEventListener("DOMContentLoaded", () => {
    const formatCurrency = (val) =>
        new Intl.NumberFormat("en-US", {
            style: "currency",
            currency: "USD",
        }).format(val);

    document.querySelectorAll(".variant-select").forEach((select) => {
        const productId = select.getAttribute("data-product-id");
        const updatePriceAndStock = () => {
            const opt = select.options[select.selectedIndex];
            if (!opt) return;

            const price = parseFloat(opt.getAttribute("data-price"));
            const stock = parseInt(opt.getAttribute("data-stock"), 10);

            const priceEl = document.getElementById(`price-${productId}`);
            const stockEl = document.getElementById(`stock-${productId}`);
            const btn = document.getElementById(`btn-reserve-${productId}`);

            if (priceEl) priceEl.textContent = formatCurrency(price);

            if (stockEl) {
                if (stock > 10) {
                    stockEl.textContent = "In Stock";
                    stockEl.className =
                        "text-xs font-semibold mt-0.5 text-emerald-600";
                    btn.disabled = false;
                } else if (stock > 0) {
                    stockEl.textContent = `Only ${stock} left`;
                    stockEl.className =
                        "text-xs font-semibold mt-0.5 text-amber-500";
                    btn.disabled = false;
                } else {
                    stockEl.textContent = "Out of Stock";
                    stockEl.className =
                        "text-xs font-semibold mt-0.5 text-red-500";
                    btn.disabled = true;
                }
            }
        };

        select.addEventListener("change", updatePriceAndStock);
        updatePriceAndStock();
    });

    document.querySelectorAll(".btn-reserve").forEach((button) => {
        button.addEventListener("click", async (e) => {
            const productId = e.target.getAttribute("data-product-id");
            const select = document.getElementById(`select-${productId}`);
            const variantId = select.value;

            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = "Reserving...";

            try {
                const response = await fetch(`/api/reserve/${variantId}`, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                    },
                });

                const data = await response.json();

                if (response.ok) {
                    showToast(
                        "Item reserved successfully! Redirecting...",
                        "success",
                    );
                    setTimeout(() => {
                        window.location.href = `/checkout/${data.data.reservation_id}`;
                    }, 1000);
                } else {
                    showToast(
                        data.message || "Failed to reserve item.",
                        "error",
                    );
                    button.disabled = false;
                    button.textContent = originalText;
                }
            } catch (error) {
                showToast("A network error occurred.", "error");
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    });

    function showToast(message, type = "error") {
        const container = document.getElementById("toast-container");
        const toast = document.createElement("div");

        let bgColor = type === "success" ? "bg-emerald-600" : "bg-red-600";

        toast.className = `toast-enter ${bgColor} text-white px-5 py-3 rounded-xl shadow-lg text-sm font-semibold flex items-center gap-3`;

        if (type === "success") {
            toast.innerHTML = `<svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg> ${message}`;
        } else {
            toast.innerHTML = `<svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg> ${message}`;
        }

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = "0";
            toast.style.transform = "translateY(10px)";
            toast.style.transition = "all 0.3s ease";
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
});
