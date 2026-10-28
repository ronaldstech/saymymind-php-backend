<?php
declare(strict_types=1);

final class Http
{
    public static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            self::json(400, [
                'success' => false,
                'error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
            ]);
        }

        return $data;
    }

    public static function method(string $expected): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($expected)) {
            header('Allow: ' . strtoupper($expected));
            self::json(405, [
                'success' => false,
                'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'HTTP method not allowed.'],
            ]);
        }
    }
}
