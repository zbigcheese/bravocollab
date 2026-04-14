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

    'debug' => false,
];
