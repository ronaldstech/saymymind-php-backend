<?php
declare(strict_types=1);

final class AuthRoutes
{
    public static function register(PDO $pdo, array $config): never
    {
        Http::method('POST');
        $input = Http::body();

        // This first endpoint creates an anonymous account. No email or password
        // is collected because the current schema has no credential/recovery table.
        $displayName = null;
        if (isset($input['display_name'])) {
            if (!is_string($input['display_name'])) {
                Response::error(422, 'INVALID_DISPLAY_NAME', 'display_name must be a string.');
            }
            $displayName = trim($input['display_name']);
            if ($displayName === '' || mb_strlen($displayName) > 50) {
                Response::error(422, 'INVALID_DISPLAY_NAME', 'display_name must contain 1–50 characters.');
            }
        }

        $username = null;
        if (isset($input['username']) && $input['username'] !== '') {
            if (!is_string($input['username'])) {
                Response::error(422, 'INVALID_USERNAME', 'username must be a string.');
            }
            $username = trim($input['username']);
            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
                Response::error(422, 'INVALID_USERNAME', 'username must be 3–30 characters using letters, numbers, or underscores.');
            }
        }

        $token = AnonymousId::sessionToken();
        $tokenHash = AnonymousId::tokenHash($token);
        $expiresAt = (new DateTimeImmutable('now'))
            ->modify('+' . max(1, (int) $config['session_ttl_days']) . ' days')
            ->format('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            // Retry a public ID if an extremely unlikely unique collision occurs.
            $userId = null;
            $publicId = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $candidate = AnonymousId::generate();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (public_id, account_role, status) VALUES (?, 'user', 'active')"
                    );
                    $stmt->execute([$candidate]);
                    $userId = (int) $pdo->lastInsertId();
                    $publicId = $candidate;
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000' || $attempt === 2) {
                        throw $e;
                    }
                }
            }

            if ($userId === null || $publicId === null) {
                throw new RuntimeException('Could not create an anonymous user.');
            }

            $profile = $pdo->prepare(
                'INSERT INTO profiles (user_id, username, display_name) VALUES (?, ?, ?)'
            );
            $profile->execute([$userId, $username, $displayName]);

            $settings = $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)');
            $settings->execute([$userId]);

            $session = $pdo->prepare(
                'INSERT INTO auth_sessions (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            );
            $session->bindValue(1, $userId, PDO::PARAM_INT);
            $session->bindValue(2, $tokenHash, PDO::PARAM_LOB);
            $session->bindValue(3, $expiresAt, PDO::PARAM_STR);
            $session->execute();

            $pdo->commit();

            Response::ok([
                'user' => [
                    'id' => $userId,
                    'public_id' => $publicId,
                    'username' => $username,
                    'display_name' => $displayName,
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
                'important' => 'Save this token securely. It is only returned at registration and cannot currently be recovered.',
            ], 201);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                Response::error(409, 'USERNAME_UNAVAILABLE', 'That username may already be in use.');
            }

            error_log('Registration database error: ' . $e->getMessage());
            Response::error(500, 'REGISTRATION_FAILED', 'Could not create the account.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Registration error: ' . $e->getMessage());
            Response::error(500, 'REGISTRATION_FAILED', 'Could not create the account.');
        }
    }

    public static function session(PDO $pdo, array $config): never
    {
        Http::method('POST');
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];
        $sessionId = $auth['session']['session_id'];
        $token = $auth['token'];

        // Renew session by extending expiry
        $expiresAt = (new DateTimeImmutable('now'))
            ->modify('+' . max(1, (int) $config['session_ttl_days']) . ' days')
            ->format('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare('UPDATE auth_sessions SET expires_at = ?, last_used_at = NOW() WHERE id = ? AND user_id = ?');
            $stmt->execute([$expiresAt, $sessionId, $userId]);
        } catch (Throwable $e) {
            error_log('Session renew error: ' . $e->getMessage());
            Response::error(500, 'SESSION_RENEW_FAILED', 'Could not renew session.');
        }

        Response::ok([
            'user' => $auth['user'],
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
        ]);
    }

    public static function logout(PDO $pdo, array $config): never
    {
        Http::method('POST');
        $auth = Auth::requireAuth($pdo);
        $sessionId = $auth['session']['session_id'];

        try {
            $stmt = $pdo->prepare('UPDATE auth_sessions SET revoked_at = NOW() WHERE id = ?');
            $stmt->execute([$sessionId]);
        } catch (Throwable $e) {
            error_log('Logout error: ' . $e->getMessage());
            Response::error(500, 'LOGOUT_FAILED', 'Could not revoke session.');
        }

        Response::ok([
            'revoked' => true,
        ]);
    }
}
