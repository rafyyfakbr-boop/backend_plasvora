<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rekonsiliasi & kadaluarsa otomatis pesanan pending_payment setiap 5 menit.
// Pada XAMPP lokal, jalankan `php artisan schedule:work` atau jadwalkan `php artisan schedule:run` via Task Scheduler.
Schedule::command('orders:expire-unpaid')
    ->everyFiveMinutes()
    ->withoutOverlapping();
