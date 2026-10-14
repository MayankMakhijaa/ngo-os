<?php
return [
    'session' => [
        'name'             => env('SESSION_NAME', 'fwngo_session'),
        // Logged-in users are signed out after this many minutes without activity.
        'idle_minutes'     => (int) env('SESSION_IDLE_MINUTES', 30),
        // Hard limit on a single session regardless of activity.
        'absolute_minutes' => (int) env('SESSION_ABSOLUTE_MINUTES', 720),
        // "auto" = secure cookie when the request is HTTPS. Set "true" in production behind HTTPS.
        'secure_cookie'    => env('SESSION_SECURE_COOKIE', 'auto'),
        'same_site'        => 'Lax',
        'save_path'        => storage_path('sessions'),
    ],
    'login' => [
        'max_attempts_per_email' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'max_attempts_per_ip'    => (int) env('LOGIN_MAX_ATTEMPTS_IP', 30),
        'decay_minutes'          => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
    ],
    'password_reset' => [
        'token_ttl_minutes'   => 60,
        'max_requests_per_hour' => 5,
    ],
    'invite' => [
        'token_ttl_hours' => 72,
    ],
    'password' => [
        'min_length' => 10,
    ],
    'uploads' => [
        'max_logo_bytes' => 2 * 1024 * 1024,
        'logo_mimes'     => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'],
    ],
    // Trust X-Forwarded-For only when the app sits behind a proxy you control (e.g. Cloudflare).
    'trust_proxy_headers' => env_bool('TRUST_PROXY_HEADERS', false),
];
