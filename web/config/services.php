<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'supabase_storage' => [
        // Keep local transport until existing photo references are reconciled before cutover.
        'photo_transport' => env('MEMBER_APPLICATION_PHOTO_STORAGE', 'local'),
        'url' => env('SUPABASE_STORAGE_URL'),
        'secret_key' => env('SUPABASE_STORAGE_SECRET_KEY'),
        'key_type' => env('SUPABASE_STORAGE_KEY_TYPE', 'secret'),
        'bucket' => 'member-application-photos',
        'connect_timeout' => env('SUPABASE_STORAGE_CONNECT_TIMEOUT', 5),
        'timeout' => env('SUPABASE_STORAGE_TIMEOUT', 20),
    ],

    'supabase' => [
    'url' => env('SUPABASE_URL'),
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
    'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
    'invitation_redirect_url' => env('SUPABASE_INVITATION_REDIRECT_URL'),
    'activation_cache_store' => env('SUPABASE_ACTIVATION_CACHE_STORE', 'database'),
],

];
