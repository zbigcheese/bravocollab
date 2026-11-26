<?php
/**
 * BravoCollab Configuration
 * Update these values for your hosting environment.
 */
return [
    'db_host'     => 'localhost',
    'db_name'     => 'bravo_organizer',
    'db_user'     => 'bravo_user',
    'db_pass'     => 'CHANGE_ME',
    'db_port'     => 3306,

    'base_url'    => 'http://localhost/BravoCollab',
    'app_name'    => 'BravoCollab',

    'upload_dir'       => __DIR__ . '/../uploads',
    'max_upload_size'  => 10 * 1024 * 1024, // 10MB

    'mail_from'      => 'noreply@bravo.org',
    'mail_from_name' => 'BravoCollab',

    'invitation_expiry_days' => 7,
    'session_lifetime'       => 7200, // 2 hours

    // Web Push (PWA notifications). Generate ONCE via:
    //   php tools/generate_vapid.php
    // and paste the two values printed. Rotating them invalidates every
    // existing push subscription, so generate once and keep them.
    'vapid_public_key'  => '',
    'vapid_private_key' => '',
    'vapid_subject'     => 'mailto:admin@bravo.org.rs',

    // Google Calendar OAuth — get these by creating an OAuth 2.0 Client ID
    // (type: Web application) at https://console.cloud.google.com/apis/credentials.
    // Authorized redirect URI must be exactly: <base_url>/index.php?page=google_callback
    // Enable the "Google Calendar API" for the same project.
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Anthropic API — powers the per-user "Infographic video" feature
    // (grant it per user in the Features column of Admin → Users). Create a key at
    // https://platform.claude.com/settings/keys. Server-side only; never sent
    // to the browser. Leave empty to disable the AI steps.
    'anthropic_api_key' => '',
    // Model + effort for storyboard generation. Cheaper alternatives:
    // 'claude-sonnet-5-5' ($2/$10 per MTok) or 'claude-haiku-5-5' ($0.10/$0.50).
    'anthropic_model'   => 'claude-opus-5-5',
    'anthropic_effort'  => 'low',              // low | medium | high
    'video_daily_ai_limit'  => 25,             // Claude calls per user per day
    'video_max_upload_size' => 60 * 1024 * 1024, // rendered MP4 (PHP upload_max_filesize must allow it)
    'video_brand_domain'    => 'bravo.org.rs',

    'debug' => false,
];
