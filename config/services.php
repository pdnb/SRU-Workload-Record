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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'uapp' => [
        'client_id' => env('UAPP_OAUTH_CLIENT_ID'),
        'client_secret' => env('UAPP_OAUTH_CLIENT_SECRET'),
        'redirect' => env('UAPP_OAUTH_REDIRECT_URI'),
        'authorize_url' => env('UAPP_OAUTH_AUTHORIZE_URL'),
        'token_url' => env('UAPP_OAUTH_TOKEN_URL'),
        'userinfo_url' => env('UAPP_OAUTH_USERINFO_URL'),
        'allowed_domain' => env('UAPP_EMAIL_DOMAIN', 'sru.ac.th'),
    ],

];
