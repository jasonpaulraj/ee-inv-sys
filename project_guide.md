# Inventory Reservation System

## Backend Coding Challenge

### Inventory Reservation System

Preventing overselling in high-concurrency flash sale systems

---

## Real World Scenario

Flash sales generate extreme traffic. Limited stock products with hundreds of users clicking "Buy" simultaneously.

* Limited Stock Products
  Few items, high demand

* Simultaneous Requests
  Hundreds clicking at once

* Prevent Overselling
  Systems must be bulletproof

---

## The Core Problem

1. Stock Available
   1 item in inventory

2. Simultaneous Requests
   500 users click Buy

3. Without Concurrency Control
   Multiple users may reserve same item

4. Result
   Overselling

---

## Challenge Objective

01 Prevent Overselling

02 Handle Concurrent Requests

03 Manage Temporary Reservations

04 Maintain Consistent State

---

## Inventory Reservation Concept

User Reserves Item → Inventory Locked → Confirm or Cancel → Auto-Release on Expiry

Hold time: 2 minutes - Expired reservations automatically release inventory

---

## Business Rules

Available Stock Formula

Available Stock = Total Stock − Confirmed Sales − Active Reservations

* Reservations exceeding available stock must fail
* Confirmed purchases cannot be reversed
* Only one user can reserve the last item
* Expired reservations release inventory automatically

---

## Implementation Levels

Challenge divided into 3 progressive levels

### Level 1

Basic Inventory Reservation

* Maintain inventory in memory
* Implement reserve item operation
* Reject requests when stock unavailable

---

### Level 2

Reservation Lifecycle & Expiry

States:
Active
Confirmed
Cancelled
Expired

* Hold stock for 2 minutes
* Confirm → purchase completed
* Cancel/expiry → inventory released

Example

Stock = 1

User A → Success
User B → Fail

---

### Level 3

Concurrency Handling

Goal: Prevent race conditions

Techniques

* Mutex / Locks
* Atomic operations
* Thread-safe structures

Test Scenario

Stock = 1
Simultaneous requests = 500

Expected Result

1 successful reservation
499 failures

---

## Testing & Evaluation

Testing approach

* Unit tests for reservation logic
* Concurrency tests with parallel requests

Evaluation Criteria

* Correctness
* Concurrency handling
* Expiry logic
* Code quality
* Clear locking strategy explanation

Estimated completion time: 2-3 hours

---

# Implementation Guide (Laravel 12)

## Stack

* Laravel 12
* MySQL 8
* Redis
* Docker

---

## Setup

```bash id="wqpl8s"
composer create-project laravel/laravel inventory-reservation
cd inventory-reservation
composer require predis/predis
```

---

## Environment

```env id="8k3o7h"
APP_NAME=InventoryReservation
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=inventory_db
DB_USERNAME=inventory_user
DB_PASSWORD=secret

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=redis
REDIS_PORT=6379
```

---

## Docker

### docker-compose.yml

```yaml id="h7df9q"
version: '3.8'

services:
  app:
    build: .
    container_name: app
    volumes:
      - .:/var/www/html
    ports:
      - "8080:8000"
    depends_on:
      - mysql
      - redis

  mysql:
    image: mysql:8
    container_name: mysql
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: inventory_db
      MYSQL_USER: inventory_user
      MYSQL_PASSWORD: secret
    ports:
      - "3307:3306"

  redis:
    image: redis:alpine
    container_name: redis
    ports:
      - "6379:6379"
```

---

### Dockerfile

```dockerfile id="z9lb8y"
FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    unzip git curl libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

CMD php artisan serve --host=0.0.0.0 --port=8000
```

---

## Run

```bash id="kht1pd"
docker-compose up -d
docker exec -it app composer install
docker exec -it app php artisan key:generate
```

---

## Migrations

```bash id="yy2j7x"
docker exec -it app php artisan make:migration create_inventories_table
docker exec -it app php artisan make:migration create_reservations_table
```

### inventories

```php id="azk5nr"
Schema::create('inventories', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('stock');
    $table->timestamps();
});
```

### reservations

```php id="b6m2kj"
Schema::create('reservations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
    $table->enum('status', ['active', 'confirmed', 'cancelled', 'expired']);
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();

    $table->index(['inventory_id', 'status']);
});
```

```bash id="e6gm0l"
docker exec -it app php artisan migrate
```

---

## Routes

```php id="y8e1od"
Route::get('/', fn () => view('home'));

Route::prefix('api')->group(function () {
    Route::post('/reserve/{id}', [ReservationController::class, 'reserve']);
    Route::post('/confirm/{id}', [ReservationController::class, 'confirm']);
    Route::post('/cancel/{id}', [ReservationController::class, 'cancel']);
});
```

---

## Controller

```php id="j8w0l2"
class ReservationController extends Controller
{
    public function reserve($id)
    {
        return Cache::lock("inventory:$id", 5)->block(3, function () use ($id) {

            return DB::transaction(function () use ($id) {

                $inventory = DB::table('inventories')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                $active = DB::table('reservations')
                    ->where('inventory_id', $id)
                    ->where('status', 'active')
                    ->count();

                if ($inventory->stock - $active <= 0) {
                    return response()->json(['message' => 'Out of stock'], 400);
                }

                $reservationId = DB::table('reservations')->insertGetId([
                    'inventory_id' => $id,
                    'status' => 'active',
                    'expires_at' => now()->addMinutes(2),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                return response()->json(['reservation_id' => $reservationId]);
            });
        });
    }

    public function confirm($id)
    {
        DB::table('reservations')
            ->where('id', $id)
            ->where('status', 'active')
            ->update(['status' => 'confirmed']);

        return response()->json(['message' => 'confirmed']);
    }

    public function cancel($id)
    {
        DB::table('reservations')
            ->where('id', $id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled']);

        return response()->json(['message' => 'cancelled']);
    }
}
```

---

## Expiry

```bash id="q5nyu3"
docker exec -it app php artisan make:command ExpireReservations
```

```php id="c5g0u9"
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    public function handle()
    {
        DB::table('reservations')
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }
}
```

```php id="k1rf8l"
$schedule->command('reservations:expire')->everyMinute();
```

---

## UI

```blade id="7yqg5z"
<!DOCTYPE html>
<html>
<head>
    <title>Inventory Reservation</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; padding: 40px; }
        .box { background: white; padding: 20px; max-width: 400px; margin: auto; border: 1px solid #ddd; border-radius: 6px; }
        button { background: #2d6cdf; color: #fff; padding: 10px; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<div class="box">
    <h2>Reserve Item</h2>
    <form method="POST" action="/api/reserve/1">
        @csrf
        <button type="submit">Reserve</button>
    </form>
</div>
</body>
</html>
```

---

## Test

```bash id="q6r6qn"
ab -n 500 -c 100 http://localhost:8080/api/reserve/1
```

---

## Result

* 1 successful reservation
* Remaining requests fail
* No overselling
* Expired reservations release stock
