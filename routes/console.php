<?php

use App\Console\Commands\ExpireReservations;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ExpireReservations::class)->everyMinute();
