document.addEventListener("DOMContentLoaded", () => {
    const select = document.getElementById("variant-select");
    const btnStart = document.getElementById("btn-start");
    const logBox = document.getElementById("log-box");

    let targetStock = 0;

    async function loadVariants() {
        try {
            const r = await fetch("/api/test/variants");
            const variants = await r.json();

            select.innerHTML = "";
            variants.forEach((v) => {
                const opt = document.createElement("option");
                opt.value = v.id;
                opt.textContent = `${v.product_name} — ${v.name} (Available: ${v.available} / Total: ${v.stock})`;
                opt.dataset.stock = v.available;
                select.appendChild(opt);
            });
        } catch (e) {
            select.innerHTML = '<option value="">Failed to load data</option>';
        }
    }

    window.resetDb = async function () {
        if (
            !confirm(
                "This will wipe all data and reseed the entire database. Continue?",
            )
        )
            return;

        btnStart.disabled = true;
        logBox.innerHTML = "";
        log("Resetting database...");
        try {
            const r = await fetch("/api/test/reset", { method: "POST" });
            const data = await r.json();
            log(data.message);
            await loadVariants();
            log("Ready.");
        } catch (e) {
            log("Error resetting DB.", true);
        } finally {
            btnStart.disabled = false;
        }
    };

    function log(msg, isErr = false) {
        const div = document.createElement("div");
        div.textContent = `[${new Date().toISOString().split("T")[1]}] ` + msg;
        if (isErr) div.style.color = "#ef4444";
        logBox.appendChild(div);
        logBox.scrollTop = logBox.scrollHeight;
    }

    window.runTest = async function () {
        const variantId = select.value;
        const numRequests = parseInt(
            document.getElementById("num-requests").value,
            10,
        );
        const stock = parseInt(
            select.options[select.selectedIndex].dataset.stock,
            10,
        );
        targetStock = stock;

        if (!variantId || isNaN(numRequests) || numRequests <= 0) {
            alert("Invalid input parameters.");
            return;
        }

        btnStart.disabled = true;
        select.disabled = true;
        document.getElementById("num-requests").disabled = true;
        document.getElementById("btn-reset").disabled = true;

        document.getElementById("progress-container").style.display = "block";
        document.getElementById("results").style.display = "block";
        logBox.innerHTML = "";

        const progBar = document.getElementById("progress-bar");
        const progPct = document.getElementById("progress-pct");

        let completed = 0;
        let success = 0;
        let failed = 0;

        document.getElementById("stat-total").textContent = numRequests;
        document.getElementById("stat-success").textContent = 0;
        document.getElementById("stat-fail").textContent = 0;

        log(
            `Initializing ${numRequests} concurrent requests for Variant #${variantId} (Initial Available: ${stock})`,
        );

        const promises = [];
        const endpoint = `/api/reserve/${variantId}`;

        const updateUI = () => {
            completed++;
            const pct = Math.floor((completed / numRequests) * 100);
            progBar.style.width = pct + "%";
            progPct.textContent = pct + "%";
        };

        const startTime = Date.now();

        for (let i = 0; i < numRequests; i++) {
            const randomKey =
                Math.random().toString(36).substring(2, 15) +
                Math.random().toString(36).substring(2, 15);

            const p = fetch(endpoint, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Idempotency-Key": randomKey,
                },
            })
                .then(async (r) => {
                    const isOk = r.ok;
                    updateUI();
                    if (isOk) {
                        success++;
                        document.getElementById("stat-success").textContent =
                            success;
                    } else {
                        failed++;
                        document.getElementById("stat-fail").textContent =
                            failed;
                    }
                })
                .catch((e) => {
                    updateUI();
                    failed++;
                    document.getElementById("stat-fail").textContent = failed;
                });

            promises.push(p);
        }

        await Promise.all(promises);

        const duration = ((Date.now() - startTime) / 1000).toFixed(2);

        log(`Test completed in ${duration}s.`);
        log(`Total Success: ${success}`);
        log(`Total Failed (Oversell prevented): ${failed}`);

        if (success > targetStock) {
            log(
                `CRITICAL FAILURE: Oversold! Permitted ${success} reserves on an initial available stock of ${targetStock}.`,
                true,
            );
        } else if (success === targetStock && targetStock > 0) {
            log(
                `SUCCESS: Reserved exactly ${targetStock} items. Locking prevented over-selling perfectly.`,
            );
        } else {
            log(
                `INFO: Not all available stock was reserved (${success}/${targetStock}). This is nominal if requests were very few, or if stock was 0.`,
            );
        }

        await loadVariants();

        btnStart.disabled = false;
        select.disabled = false;
        document.getElementById("num-requests").disabled = false;
        document.getElementById("btn-reset").disabled = false;
    };
});
