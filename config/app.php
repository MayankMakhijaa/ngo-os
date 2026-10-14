<?php
// Application configuration. Values come from the environment (.env); nothing secret is hardcoded here.
return [
    'name'      => env('APP_NAME', 'Fameweb NGO Cloud'),
    'env'       => env('APP_ENV', 'production'),
    'debug'     => env_bool('APP_DEBUG', false),
    // Full public URL of the application, e.g. https://ngo.fameweb.in (no trailing slash).
    // Leave empty to auto-detect from the request (works for subfolders too).
    'url'       => rtrim((string) env('APP_URL', ''), '/'),
    'timezone'  => env('APP_TIMEZONE', 'Asia/Kolkata'),
    'currency'  => env('APP_CURRENCY', 'INR'),
    // One-time secret required by the web installer. Remove after installation.
    'install_token' => env('INSTALL_TOKEN', ''),
    'per_page'  => 20,
];
