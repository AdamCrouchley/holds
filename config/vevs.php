<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VeVS API Base URL
    |--------------------------------------------------------------------------
    |
    | Use the full base URL including the API key segment. For example:
    |   https://rental.jimny.co.nz/api/7hNDOb8wBJHC6Lv3RLS4xFGnXcZXojaZ5IV7WDcZ6QUpWNJtHX
    |
    | This is the prefix before you append endpoints like "/Reservation".
    |
    */
    'base_url' => env('VEVS_BASE_URL', 'https://rental.jimny.co.nz/api/KEY_GOES_HERE'),

    /*
    |--------------------------------------------------------------------------
    | VeVS API Key
    |--------------------------------------------------------------------------
    |
    | Not used when the key is embedded in the path. Leave blank unless
    | VeVS changes their API to require a separate key header or param.
    |
    */
    'api_key'  => env('VEVS_API_KEY', null),
];
