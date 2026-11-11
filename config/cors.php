<?php

/*
| Plan §7: CORS is disabled. The API is called server-to-server; browsers on
| unknown origins get no CORS headers, so cross-origin calls are blocked.
*/

return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
