<?php

// The staff Mini App (/tg/*). The bot token checks Telegram's initData signature; it is the same token the bot uses.
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', env('BOT_TOKEN', '')),

    // initData older than this is refused ("Oyna eskirgan"): a day, so a Mini App left open in the background still works
    'init_data_ttl' => (int) env('TG_INIT_DATA_TTL', 86400),

    // evidence farther than this from the object's known point is refused
    'max_distance_m' => (int) env('TG_MAX_DISTANCE_M', 300),

    // GPS readings less accurate than this are refused: try again outdoors / with GPS on
    // a staff member must stand within this distance of the street (the line from its start to its end in the official
    // list) to add a business there or prove one that has no approved place yet: no entering from home
    'street_radius_m' => (int) env('TG_STREET_RADIUS_M', 300),
    // the bot's @username for invite links (asked from Telegram when empty)
    'bot_username' => env('TG_BOT_USERNAME', ''),
    'max_accuracy_m' => (int) env('TG_MAX_ACCURACY_M', 150),

    'video_min_bytes' => 40 * 1024,          // shorter/empty recordings are refused
    'video_max_kb' => 60 * 1024,
    'photo_max_kb' => 15 * 1024,
    'comment_min' => 5,

    // bump on every change of the Mini App page (?v=N), so phones do not keep an old copy
    'asset_version' => 3,
];
