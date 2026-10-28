<?php
declare(strict_types=1);

final class AnonymousId
{
    /**
     * Generates a random, non-sequential public identifier.
     * Database uniqueness is still enforced by users.public_id.
     */
    public static function generate(int $bytes = 12): string
    {
        return 'anon_' . bin2hex(random_bytes($bytes));
    }

    public static function sessionToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function tokenHash(string $token): string
    {
        // BINARY(32) stores the raw 32-byte SHA-256 digest, not its 64-char hex form.
        return hash('sha256', $token, true);
    }
}
