<?php
declare(strict_types=1);

final class SettingsRoutes
{
    public static function dispatch(PDO $pdo): never
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'GET') {
            self::get($pdo);
        }
        if ($method === 'PATCH') {
            self::patch($pdo);
        }
        header('Allow: GET, PATCH');
        Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
    }

    private static function get(PDO $pdo): never
    {
        Http::method('GET');
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        self::ensureRow($pdo, $userId);
        Response::ok(['settings' => self::read($pdo, $userId)]);
    }

    private static function patch(PDO $pdo): never
    {
        Http::method('PATCH');
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        if ($input === []) {
            Response::error(422, 'EMPTY_BODY', 'No settings were provided.');
        }

        $booleanFields = [
            'allow_matching',
            'allow_anonymous_messages',
            'show_online_status',
            'notifications_enabled',
        ];
        $updates = [];
        $values = [];
        foreach ($booleanFields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (!is_bool($input[$field])) {
                Response::error(422, 'INVALID_SETTING', $field . ' must be a boolean.');
            }
            $updates[] = $field . ' = ?';
            $values[] = $input[$field] ? 1 : 0;
        }

        if (array_key_exists('preferred_language', $input)) {
            $language = $input['preferred_language'];
            $supportedLanguages = ['en', 'ny', 'sw', 'fr', 'pt', 'ar'];
            if (!is_string($language) || !in_array($language, $supportedLanguages, true)) {
                Response::error(422, 'INVALID_LANGUAGE', 'preferred_language is not supported.');
            }
            $updates[] = 'preferred_language = ?';
            $values[] = $language;
        }

        $allowedFields = array_merge($booleanFields, ['preferred_language']);
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                Response::error(422, 'UNKNOWN_SETTING', 'Unknown setting: ' . (string)$field . '.');
            }
        }
        if ($updates === []) {
            Response::error(422, 'EMPTY_BODY', 'No supported settings were provided.');
        }

        self::ensureRow($pdo, $userId);
        $values[] = $userId;
        $pdo->prepare('UPDATE user_settings SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
            ->execute($values);

        if (array_key_exists('allow_matching', $input) && $input['allow_matching'] === false) {
            $pdo->prepare("UPDATE match_queue_entries SET status = 'cancelled' WHERE user_id = ? AND status = 'waiting'")
                ->execute([$userId]);
        }

        Response::ok(['settings' => self::read($pdo, $userId)]);
    }

    private static function ensureRow(PDO $pdo, int $userId): void
    {
        $pdo->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
    }

    private static function read(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT allow_matching, allow_anonymous_messages, show_online_status,
                    notifications_enabled, preferred_language
             FROM user_settings WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'allow_matching' => (bool)($row['allow_matching'] ?? true),
            'allow_anonymous_messages' => (bool)($row['allow_anonymous_messages'] ?? true),
            'show_online_status' => (bool)($row['show_online_status'] ?? false),
            'notifications_enabled' => (bool)($row['notifications_enabled'] ?? true),
            'preferred_language' => (string)($row['preferred_language'] ?? 'en'),
        ];
    }
}