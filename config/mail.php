<?php
// Mail drivers: "log" writes messages to storage/logs/mail.log (default, safe for testing),
// "mail" uses PHP mail(), "smtp" talks to an SMTP server (Hostinger email, Gmail/Workspace,
// Zoho, SES, Brevo...). Fameweb administrators can override all of this under
// Admin > Email > Settings; the SMTP password entered there is stored encrypted with APP_KEY.
// Every email goes through the outbox (email_outbox) with delivery attempts and retries.
return [
    'driver'     => env('MAIL_DRIVER', 'log'),
    'from'       => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
    'from_name'  => env('MAIL_FROM_NAME', 'Fameweb NGO Cloud'),
    'host'       => env('MAIL_HOST', ''),
    'port'       => (int) env('MAIL_PORT', 587),
    // tls = STARTTLS (port 587), ssl = implicit TLS (port 465), none = plain (local relays only)
    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    'username'   => env('MAIL_USERNAME', ''),
    'password'   => env('MAIL_PASSWORD', ''),
    'timeout'    => (int) env('MAIL_TIMEOUT', 15),
    // Delivery attempts per email and the wait (minutes) before each retry.
    'max_attempts'  => 5,
    'retry_minutes' => [2, 10, 60, 360],
];
