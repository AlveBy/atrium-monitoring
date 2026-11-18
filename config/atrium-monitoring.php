<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server timings
    |--------------------------------------------------------------------------
    |
    | A middleware in the `web` group measures every request: time to the
    | first byte, database queries and their time, cache hits and misses, peak
    | memory. `sample_rate` is the share of requests measured (0.1 = one in
    | ten). Queries slower than `slow_query_ms` are logged with their SQL,
    | literals and bindings left out. `except` lists paths never measured.
    |
    */
    'server' => [
        'enabled' => (bool) env('ATRIUM_MONITORING_SERVER', true),
        'sample_rate' => (float) env('ATRIUM_MONITORING_SAMPLE_RATE', 1.0),
        'slow_query_ms' => (float) env('ATRIUM_MONITORING_SLOW_QUERY_MS', 100),
        'except' => ['up', 'atrium/rum', 'vendor/*', 'build/*', 'storage/*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Buffer
    |--------------------------------------------------------------------------
    |
    | Measurements are appended to a file (one short line each) and written
    | to the database in batches: by `atrium:monitoring:flush` every minute,
    | or as soon as the file passes `max_bytes`. With `queue` set to a queue
    | connection, that early flush runs on the queue instead of at the end of
    | the request that noticed it.
    |
    */
    'buffer' => [
        'path' => env('ATRIUM_MONITORING_BUFFER', storage_path('app/atrium-monitoring')),
        'max_bytes' => 262144,
        'queue' => env('ATRIUM_MONITORING_QUEUE'),
        'lock_store' => env('ATRIUM_MONITORING_LOCK_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real user monitoring
    |--------------------------------------------------------------------------
    |
    | A script under 2 KB in the public pages sends LCP, INP, CLS, TTFB and
    | FCP once per page view with navigator.sendBeacon. No cookies, nothing
    | personal; the address is kept without its query string and IPs are not
    | stored. Visitors with Do Not Track or Global Privacy Control are not
    | measured at all. `rate_limit` is beacons per minute from one address.
    |
    */
    'rum' => [
        'enabled' => (bool) env('ATRIUM_MONITORING_RUM', true),
        'sample_rate' => (float) env('ATRIUM_MONITORING_RUM_SAMPLE_RATE', 1.0),
        'max_bytes' => 2048,
        'rate_limit' => 30,
        // The public theme gets the script by itself; other themes put
        // <x-atrium-monitoring::rum /> in their layout.
        'theme' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime
    |--------------------------------------------------------------------------
    |
    | Every minute the front page of each active site and the addresses
    | added under "Uptime checks" are opened. After `failures` failed checks
    | in a row an incident is opened and the alerts go out; the first good
    | check closes it. The number, the e-mail addresses and the Telegram bot
    | are set on the module's settings screen.
    |
    */
    'uptime' => [
        'enabled' => (bool) env('ATRIUM_MONITORING_UPTIME', true),
        'scheme' => env('ATRIUM_MONITORING_UPTIME_SCHEME', 'https'),
        'timeout' => 10,
        'failures' => 3,
        'concurrency' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Raw measurements are kept `raw_days`, daily aggregates (percentiles of
    | every day) `aggregate_months`. `atrium:monitoring:prune` runs daily.
    |
    */
    'retention' => [
        'raw_days' => 7,
        'aggregate_months' => 13,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | The bot's token and chat may be set on the settings screen; these win
    | when set. Never commit a token.
    |
    */
    'telegram' => [
        'token' => env('ATRIUM_MONITORING_TELEGRAM_TOKEN'),
        'chat_id' => env('ATRIUM_MONITORING_TELEGRAM_CHAT_ID'),
    ],

    // Whether the module adds its tasks to Laravel's scheduler.
    'schedule' => true,
];
