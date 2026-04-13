import http from "k6/http";
import { check } from "k6";
import { uuidv4 } from "https://jslib.k6.io/k6-utils/1.4.0/index.js";

export const options = {
    scenarios: {
        exact_concurrency: {
            executor: "per-vu-iterations",
            vus: 500,
            iterations: 1,
            maxDuration: "30s",
        },
    },
};

export default function () {
    // BASE_URL is injected via -e flag; falls back to localhost for direct host runs
    const baseUrl = __ENV.BASE_URL || "http://localhost:8080";
    const url = `${baseUrl}/api/reserve/2`;

    const payload = JSON.stringify({});

    const params = {
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "Idempotency-Key": "k6-" + uuidv4(),
        },
    };

    const res = http.post(url, payload, params);

    if (res.status !== 201 && res.status !== 400) {
        console.log(
            `VU ${__VU} got unexpected status ${res.status}: ${res.body}`,
        );
    }

    check(res, {
        "is status 201 (success — reserved)": (r) => r.status === 201,
        "is status 400 (expected — out of stock)": (r) => r.status === 400,
        "no unexpected errors (not 409 or 5xx)": (r) =>
            r.status !== 409 && r.status < 500,
    });
}
