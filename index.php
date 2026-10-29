<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'CONFIG_MISSING',
            'message' => 'Copy config/config.example.php to config/config.php and configure the database.',
        ],
    ]);
    exit;
}

$config = require $configPath;

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/AnonymousId.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Routes/AuthRoutes.php';
require_once __DIR__ . '/src/Routes/ProfileRoutes.php';
require_once __DIR__ . '/src/Routes/AvatarRoutes.php';
require_once __DIR__ . '/src/Routes/MatchmakingRoutes.php';
require_once __DIR__ . '/src/Routes/ConversationRoutes.php';
require_once __DIR__ . '/src/Routes/FriendRoutes.php';
require_once __DIR__ . '/src/Routes/SafetyRoutes.php';
require_once __DIR__ . '/src/Routes/InboxRoutes.php';
require_once __DIR__ . '/src/Routes/SettingsRoutes.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

// Normalize path to support both /api/v1/* and /api/* as requested
if (str_starts_with($path, '/api/v1/')) {
    $pathNorm = str_replace('/api/v1', '/api', $path);
} else {
    $pathNorm = $path;
}

if ($path === '/api/v1/health' || $pathNorm === '/api/health') {
    Http::method('GET');
    try {
        $pdo = Database::connect($config);
        $pdo->query('SELECT 1');
        Response::ok(['status' => 'ok', 'database' => 'connected']);
    } catch (Throwable $e) {
        error_log('Health check failed: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'The database is not available.');
    }
}

// Register / anonymous register
if ($path === '/api/v1/auth/register' || $pathNorm === '/api/auth/register' || $pathNorm === '/api/anonymous/register') {
    try {
        $pdo = Database::connect($config);
        AuthRoutes::register($pdo, $config);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not connect to the database.');
    }
}

// Auth session (create or renew)
if ($pathNorm === '/api/auth/session') {
    try {
        $pdo = Database::connect($config);
        AuthRoutes::session($pdo, $config);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not connect to the database.');
    }
}

// Auth logout
if ($pathNorm === '/api/auth/logout') {
    try {
        $pdo = Database::connect($config);
        AuthRoutes::logout($pdo, $config);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not connect to the database.');
    }
}

if ($pathNorm === '/api/avatars') {
    try {
        $pdo = Database::connect($config);
        AvatarRoutes::index($pdo);
    } catch (Throwable $e) {
        error_log('Avatar catalog error: ' . $e->getMessage());
        Response::error(500, 'AVATAR_CATALOG_FAILED', 'Could not load avatars.');
    }
}

if ($pathNorm === '/api/matchmaking') {
    try {
        $pdo = Database::connect($config);
        MatchmakingRoutes::dispatch($pdo);
    } catch (Throwable $e) {
        error_log('Matchmaking database error: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not connect to the database.');
    }
}

if ($pathNorm === '/api/conversations' || str_starts_with($pathNorm, '/api/conversations/')) {
    try {
        $pdo = Database::connect($config);
        ConversationRoutes::dispatch($pdo, $pathNorm);
    } catch (Throwable $e) {
        error_log('Conversations database error: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not load conversations.');
    }
}

if ($pathNorm === '/api/friends' || str_starts_with($pathNorm, '/api/friends/')) {
    try {
        $pdo = Database::connect($config);
        FriendRoutes::dispatch($pdo, $pathNorm);
    } catch (Throwable $e) {
        error_log('Friend API error: ' . $e->getMessage());
        Response::error(500, 'FRIEND_REQUEST_FAILED', 'Could not add this friend.');
    }
}
if ($pathNorm === '/api/blocks' || $pathNorm === '/api/reports') {
    try {
        $pdo = Database::connect($config);
        SafetyRoutes::dispatch($pdo, $pathNorm);
    } catch (Throwable $e) {
        error_log('Safety API error: ' . $e->getMessage());
        Response::error(500, 'SAFETY_ACTION_FAILED', 'Could not complete this safety action.');
    }
}
if ($pathNorm === '/api/settings') {
    try {
        $pdo = Database::connect($config);
        SettingsRoutes::dispatch($pdo);
    } catch (Throwable $e) {
        error_log('Settings API error: ' . $e->getMessage());
        Response::error(500, 'SETTINGS_FAILED', 'Could not update your settings.');
    }
}
if ($pathNorm === '/api/inbox' || str_starts_with($pathNorm, '/api/inbox/')) {
    try {
        $pdo = Database::connect($config);
        InboxRoutes::dispatch($pdo, $pathNorm);
    } catch (Throwable $e) {
        error_log('Inbox API error: ' . $e->getMessage());
        Response::error(500, 'INBOX_FAILED', 'Could not complete the inbox request.');
    }
}

// Profile routes
if ($pathNorm === '/api/profile/me') {
    try {
        $pdo = Database::connect($config);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            ProfileRoutes::meGet($pdo, $config);
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'PATCH') {
            ProfileRoutes::mePatch($pdo, $config);
        } else {
            Http::method('GET'); // will trigger method not allowed
        }
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        Response::error(503, 'DATABASE_UNAVAILABLE', 'Could not connect to the database.');
    }
}

Response::error(404, 'NOT_FOUND', 'API endpoint not found.');
