<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* reminder alerts */
Schedule::command('app:send-event-reminders')->everyMinute();
Schedule::command('app:send-birthday-wishes')->dailyAt('16:06');
