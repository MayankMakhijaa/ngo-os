<?php
declare(strict_types=1);
// Prints a new random APP_KEY for .env. Keep it secret and never change it once data is
// encrypted with it: identity documents, email bodies and SMTP passwords depend on it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)), PHP_EOL;
