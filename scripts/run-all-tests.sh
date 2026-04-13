#!/bin/bash
set -e
echo "Running PHPUnit Tests"
docker compose exec app ./vendor/bin/phpunit
echo "Unit & Feature Tests Passed."
echo ""
echo "Running k6 Concurrency Load Test"
docker compose --profile testing run --rm k6 run \
    -e BASE_URL=http://app:8000 \
    /k6/reserve.js
echo ""
echo "ALL TESTS COMPLETED!"
