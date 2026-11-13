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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'stripe' => [
        'mode' => env('STRIPE_MODE', 'test'),
        'key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'test_key' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'test_secret' => env('STRIPE_TEST_SECRET_KEY'),
        'test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
        'live_key' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
        'live_secret' => env('STRIPE_LIVE_SECRET_KEY'),
        'live_webhook_secret' => env('STRIPE_LIVE_WEBHOOK_SECRET'),
    ],

    /*
    | OpenStreetMap Nominatim (free): forward-geocode typed addresses to lat/lng when the
    | client does not send coordinates, then match operational areas by GPS radius.
    | https://operations.osmfoundation.org/policies/nominatim/ — use a stable User-Agent.
    */
    'nominatim' => [
        'forward_geocode_enabled' => filter_var(env('NOMINATIM_FORWARD_GEOCODE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'), '/'),
        // Keep below mobile client timeouts (often 15s) — geocode is a last resort only.
        'timeout' => (int) env('NOMINATIM_TIMEOUT', 4),
        'connect_timeout' => (int) env('NOMINATIM_CONNECT_TIMEOUT', 2),
        'user_agent' => env('NOMINATIM_USER_AGENT'),
    ],

    /*
    | Mobile social sign-in (POST /api/auth/google, POST /api/auth/apple).
    | Comma-separated client IDs if you have separate iOS/Android OAuth clients.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', env('PUBLIC_GOOGLE_CLIENT_ID')),
        'client_ids' => env('GOOGLE_CLIENT_IDS', env('PUBLIC_GOOGLE_CLIENT_IDS')),
    ],

    'apple' => [
        // iOS React Native: identity token `aud` = Bundle ID (e.g. com.company.tandil).
        // Web Sign in with Apple: `aud` = Services ID — add both to APPLE_CLIENT_IDS if needed.
        'client_id' => env('APPLE_CLIENT_ID', env('PUBLIC_APPLE_BUNDLE_ID', env('APPLE_BUNDLE_ID'))),
        'client_ids' => env('APPLE_CLIENT_IDS', env('PUBLIC_APPLE_BUNDLE_IDS')),
    ],

    /*
    | Forgot password SMS (POST /api/auth/forgot-password, verify-otp, reset-password).
    | Driver: log (local/tests), twilio (Programmable SMS + app OTP), twilio_verify (Twilio Verify service).
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'api_key_sid' => env('TWILIO_API_KEY_SID'),
            'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
            'from' => env('TWILIO_FROM'),
            'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        ],
        'password_reset' => [
            'otp_ttl_seconds' => (int) env('PASSWORD_RESET_OTP_TTL_SECONDS', 600),
            'reset_token_ttl_seconds' => (int) env('PASSWORD_RESET_TOKEN_TTL_SECONDS', 900),
            'resend_cooldown_seconds' => (int) env('PASSWORD_RESET_RESEND_COOLDOWN_SECONDS', 60),
            'max_sends_per_hour' => (int) env('PASSWORD_RESET_MAX_SENDS_PER_HOUR', 3),
            'max_verify_attempts' => (int) env('PASSWORD_RESET_MAX_VERIFY_ATTEMPTS', 5),
            'lockout_minutes' => (int) env('PASSWORD_RESET_LOCKOUT_MINUTES', 15),
            'message_template' => env(
                'PASSWORD_RESET_SMS_TEMPLATE',
                'Your TANDIL verification code is :code. Valid for :minutes minutes.'
            ),
        ],
    ],

    /*
    | Firebase Cloud Messaging — Android push for background + killed app states.
    | driver: auto|log|legacy|http_v1
    | - auto: http_v1 if service-account fields set, else legacy if FCM_SERVER_KEY set, else log
    | - log: records pushes locally (tests / local without Firebase)
    | - legacy: FCM server key (https://fcm.googleapis.com/fcm/send)
    | - http_v1: service account JWT → FCM HTTP v1
    */
    'fcm' => [
        'enabled' => filter_var(env('FCM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'driver' => env('FCM_DRIVER', 'auto'),
        'server_key' => env('FCM_SERVER_KEY'),
        'legacy_url' => env('FCM_LEGACY_URL', 'https://fcm.googleapis.com/fcm/send'),
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => env('FCM_PRIVATE_KEY'),
        'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'tandil_default'),
    ],

    /*
    | Apple Push Notification service — iOS only. Does not use Firebase.
    | driver: auto|log|http
    | Auth key is the .p8 file from Apple Developer → Keys (Apple Push Notifications service).
    */
    'apns' => [
        'enabled' => filter_var(env('APNS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'driver' => env('APNS_DRIVER', 'auto'),
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID'),
        'private_key' => env('APNS_PRIVATE_KEY'),
        'endpoint' => env('APNS_USE_SANDBOX', false)
            ? 'https://api.sandbox.push.apple.com'
            : env('APNS_ENDPOINT', 'https://api.push.apple.com'),
    ],

];
