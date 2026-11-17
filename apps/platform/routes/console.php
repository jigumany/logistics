<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('quotes:expire')->everyMinute()->withoutOverlapping();
Schedule::command('routes:purge-results')->hourly()->withoutOverlapping();

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
