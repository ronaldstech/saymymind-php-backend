<?php
declare(strict_types=1);

final class Response
{
    public static function ok(array $data, int $status = 200): never
    {
        Http::json($status, ['success' => true, 'data' => $data]);
    }

    public static function error(int $status, string $code, string $message): never
    {
        Http::json($status, [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
