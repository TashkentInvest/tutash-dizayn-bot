<?php

// Paths shared with the Telegram bot. Read through config() (not env()) so `php artisan config:cache` keeps working.
return [
    'uploads_path' => env('BOT_UPLOADS_PATH', base_path('../uploads')),
    // the token other systems send to read GET /api/v1/streets (SNOS map); empty = the API is closed
    'integration_token' => env('INTEGRATION_TOKEN', ''),
];
