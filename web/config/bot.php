<?php

// Paths shared with the Telegram bot. Read through config() (not env()) so `php artisan config:cache` keeps working.
return [
    'uploads_path' => env('BOT_UPLOADS_PATH', base_path('../uploads')),
];
