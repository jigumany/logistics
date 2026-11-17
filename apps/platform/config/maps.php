<?php

return [
    'google' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
        'billing_confirmed' => (bool) env('GOOGLE_MAPS_BILLING_CONFIRMED', false),
        'retention_permitted' => (bool) env('GOOGLE_MAPS_RETENTION_PERMITTED', false),
        'retention_hours' => (int) env('GOOGLE_MAPS_RETENTION_HOURS', 0),
        'timeout_seconds' => (int) env('GOOGLE_MAPS_TIMEOUT_SECONDS', 4),
    ],
];
