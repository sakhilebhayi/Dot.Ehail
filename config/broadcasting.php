<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "ably", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // This is the PHP backend's own outbound path for publishing
            // events into Reverb (POST /apps/{id}/events) -- deliberately
            // the internal REVERB_SERVER_HOST/PORT the reverb:start process
            // actually binds to (loopback-only in production), not the
            // public REVERB_HOST/PORT browsers connect through. Publishing
            // straight to the internal port skips an unnecessary round trip
            // out through the reverse proxy and back, and keeps the
            // server-to-server /apps/* API off the public internet
            // entirely -- only /app/{key} (the browser's path) is proxied.
            // Falls back to the public values when *_SERVER_* isn't set
            // (e.g. local dev, where there's no reverse proxy at all).
            'options' => [
                'host' => env('REVERB_SERVER_HOST', env('REVERB_HOST', 'localhost')),
                'port' => env('REVERB_SERVER_PORT', env('REVERB_PORT', 8080)),
                'scheme' => env('REVERB_SERVER_HOST') ? 'http' : env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SERVER_HOST') ? false : env('REVERB_SCHEME') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
