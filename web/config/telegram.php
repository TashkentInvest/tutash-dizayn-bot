<?php

// The staff Mini App (/tg/*). The bot token checks Telegram's initData signature; it is the same token the bot uses.
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', env('BOT_TOKEN', '')),

    // initData older than this is refused ("Oyna eskirgan"): a day, so a Mini App left open in the background still works
    'init_data_ttl' => (int) env('TG_INIT_DATA_TTL', 86400),

    // evidence farther than this from the object's known point is refused
    'max_distance_m' => (int) env('TG_MAX_DISTANCE_M', 300),

    // GPS readings less accurate than this are refused: try again outdoors / with GPS on
    'max_accuracy_m' => (int) env('TG_MAX_ACCURACY_M', 150),

    'video_min_bytes' => 40 * 1024,          // shorter/empty recordings are refused
    'video_max_kb' => 60 * 1024,
    'photo_max_kb' => 15 * 1024,
    'comment_min' => 5,

    // bump on every change of the Mini App page (?v=N), so phones do not keep an old copy
    'asset_version' => 3,
];
