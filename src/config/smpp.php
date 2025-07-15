<?php

return [
    'host' => env('SMPP_HOST', '127.0.0.1'),
    'port' => env('SMPP_PORT', 2775),
    'username' => env('SMPP_USERNAME', 'smppuser'),
    'password' => env('SMPP_PASSWORD', 'smpppass'),
    'timeout' => env('SMPP_TIMEOUT', 10000),
    'debug' => env('SMPP_DEBUG', false),
];