<?php
declare(strict_types=1);

final class Auth
{
    public static function getBearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = null;
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string)$key) === 'authorization') {
                    $authHeader = $value;
                    break;
                }
            }
        }
        if ($authHeader === null && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ($authHeader === null && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (!is_string($authHeader)) {
            return null;
        }
        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            return $token === '' ? null : $token;
        }
        return null;
    }

    /**
     * @return array{user_id:int, session_id:int, expires_at:string}|null
     */
    public static function validateSession(PDO $pdo, string $token): ?array
    {
        $tokenHash = AnonymousId::tokenHash($token);
        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, revoked_at 
             FROM auth_sessions 
             WHERE token_hash = ? 
             LIMIT 1'
        );
        $stmt->bindValue(1, $tokenHash, PDO::PARAM_LOB);
        $stmt->execute();
        $session = $stmt->fetch();
        if (!$session) {
            return null;
        }
        if (!empty($session['revoked_at'])) {
            return null;
        }
        $expiresAt = $session['expires_at'];
        if ($expiresAt) {
            $exp = strtotime($expiresAt);
            if ($exp !== false && $exp < time()) {
                return null;
            }
        }
        // Update last_used_at
        $update = $pdo->prepare('UPDATE auth_sessions SET last_used_at = NOW() WHERE id = ?');
        $update->execute([(int)$session['id']]);
        return [
            'user_id' => (int)$session['user_id'],
            'session_id' => (int)$session['id'],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{id:int, public_id:string, account_role:string, status:string}|null
     */
    public static function getUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, public_id, account_role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        return [
            'id' => (int)$user['id'],
            'public_id' => $user['public_id'],
            'account_role' => $user['account_role'],
            'status' => $user['status'],
        ];
    }

    public static function requireAuth(PDO $pdo): array
    {
        $token = self::getBearerToken();
        if ($token === null) {
            Response::error(401, 'UNAUTHORIZED', 'Authorization Bearer token required.');
        }
        $session = self::validateSession($pdo, $token);
        if ($session === null) {
            Response::error(401, 'UNAUTHORIZED', 'Invalid or expired session.');
        }
        $user = self::getUser($pdo, $session['user_id']);
        if (!$user || $user['status'] !== 'active') {
            Response::error(401, 'UNAUTHORIZED', 'Account is not active.');
        }
        return [
            'user' => $user,
            'session' => $session,
            'token' => $token,
        ];
    }
}
