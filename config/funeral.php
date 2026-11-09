<?php

return [
    'document_disk' => env('FUNERAL_DOCUMENT_DISK', 'local'),
    'pdf_scanner' => env('FUNERAL_PDF_SCANNER'),
    'privacy_url' => env('FUNERAL_PRIVACY_URL'),
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'hostname' => env('TURNSTILE_HOSTNAME'),
    ],
    'visitation' => [
        'enabled' => env('BURIAL_API_ENABLED', false),
        'url' => env('BURIAL_API_URL'),
        'key' => env('BURIAL_API_KEY'),
    ],
    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
        'version' => env('WHATSAPP_GRAPH_VERSION'),
        'phone_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'test_recipient' => env('WHATSAPP_TEST_RECIPIENT'),
        'languages' => [
            'new_request' => env('FUNERAL_NEW_REQUEST_LANGUAGE'),
            'completed' => env('FUNERAL_COMPLETED_LANGUAGE'),
        ],
    ],
    'templates' => [
        'new_request' => env('FUNERAL_NEW_REQUEST_TEMPLATE') ?: 'funeral_new_request',
        'completed' => env('FUNERAL_COMPLETED_TEMPLATE') ?: 'funeral_completed',
    ],
];
