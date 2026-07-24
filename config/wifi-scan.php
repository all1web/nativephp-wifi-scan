<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Include Hidden Networks
    |--------------------------------------------------------------------------
    |
    | Access points that broadcast no SSID show up with an empty SSID string.
    | By default they are filtered out of scan() results. Set true to keep them
    | (their BSSID is still useful for location fingerprinting).
    |
    */

    'include_hidden' => env('WIFI_SCAN_INCLUDE_HIDDEN', false),

    /*
    |--------------------------------------------------------------------------
    | Max Results
    |--------------------------------------------------------------------------
    |
    | Cap the number of access points returned from scan(). 0 means no cap.
    | Results arrive strongest-signal-first from the native layer, so a small
    | cap keeps the nearest radios.
    |
    */

    'max_results' => env('WIFI_SCAN_MAX_RESULTS', 0),

];
