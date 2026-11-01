<?php

/*
| CORS for the public landing-page endpoint only.
| Allowed origins are set in the CRM (Lead Sources → Landing pages) and applied at boot
| by AppServiceProvider. CORS only controls which browsers may call the API — it is NOT authentication.
*/
return [
    'paths' => ['api/v1/public/*'],
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', (string) env('CRM_ALLOWED_ORIGINS', ''))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-Form-Key', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
