<?php
/**
 * BravoCollab API Entry Point
 * All AJAX calls go through here: api.php?action=controller.method
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/core/Model.php';
require_once __DIR__ . '/core/Validator.php';
require_once __DIR__ . '/core/Router.php';

Auth::init();

// Idempotent additive schema migrations — runs at most once per request
// (memoized). Same self-heal pattern as index.php so users hitting the API
// directly (mobile clients, deep-linked actions) get the upgraded schema too.
if (Auth::isLoggedIn()) {
    try {
        require_once __DIR__ . '/core/SchemaUpgrades.php';
        SchemaUpgrades::ensureAll();
    } catch (Throwable $e) {
        error_log('SchemaUpgrades (api) failed: ' . $e->getMessage());
    }
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

try {
    Router::dispatch();
} catch (PDOException $e) {
    $config = require __DIR__ . '/config/config.php';
    http_response_code(500);
    echo json_encode([
        'error' => $config['debug'] ? $e->getMessage() : 'Database error',
    ]);
} catch (Exception $e) {
    $config = require __DIR__ . '/config/config.php';
    http_response_code(500);
    echo json_encode([
        'error' => $config['debug'] ? $e->getMessage() : 'Server error',
    ]);
}
