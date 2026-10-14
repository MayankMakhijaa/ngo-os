<?php
// Phase 1 mail drivers: "log" writes messages to storage/logs/mail.log (default, safe for testing),
// "mail" uses PHP mail() which works on Hostinger once the domain's email is configured.
// SMTP with delivery logs and retries arrives with the Email Automation module.
return [
    'driver'    => env('MAIL_DRIVER', 'log'),
    'from'      => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
    'from_name' => env('MAIL_FROM_NAME', 'Fameweb NGO Cloud'),
];
