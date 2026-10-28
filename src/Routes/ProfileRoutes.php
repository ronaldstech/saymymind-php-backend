<?php
declare(strict_types=1);

final class ProfileRoutes
{
    public static function meGet(PDO $pdo, array $config): never
    {
        Http::method('GET');
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];

        $stmt = $pdo->prepare(
            'SELECT u.id, u.public_id, u.account_role, u.status, u.created_at,
                    p.username, p.display_name, p.avatar_url, p.bio, p.updated_at
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        Response::ok([
            'user' => [
                'id' => (int)($profile['id'] ?? $userId),
                'public_id' => $profile['public_id'] ?? $auth['user']['public_id'],
                'account_role' => $profile['account_role'] ?? $auth['user']['account_role'],
                'status' => $profile['status'] ?? $auth['user']['status'],
                'created_at' => $profile['created_at'] ?? null,
            ],
            'profile' => [
                'username' => $profile['username'] ?? null,
                'display_name' => $profile['display_name'] ?? null,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'bio' => $profile['bio'] ?? null,
                'updated_at' => $profile['updated_at'] ?? null,
            ],
        ]);
    }

    public static function mePatch(PDO $pdo, array $config): never
    {
        Http::method('PATCH');
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];
        $input = Http::body();

        if (empty($input)) {
            Response::error(422, 'EMPTY_BODY', 'No updatable fields provided.');
        }

        $allowed = ['username', 'display_name', 'avatar_url', 'bio'];
        $updates = [];
        $params = [];

        if (array_key_exists('username', $input)) {
            $username = $input['username'];
            if ($username === null || $username === '') {
                $updates[] = 'username = ?';
                $params[] = null;
            } else {
                if (!is_string($username)) {
                    Response::error(422, 'INVALID_USERNAME', 'username must be a string.');
                }
                $username = trim($username);
                if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
                    Response::error(422, 'INVALID_USERNAME', 'username must be 3–30 characters using letters, numbers, or underscores.');
                }
                $updates[] = 'username = ?';
                $params[] = $username;
            }
        }

        if (array_key_exists('display_name', $input)) {
            $displayName = $input['display_name'];
            if ($displayName === null || $displayName === '') {
                $updates[] = 'display_name = ?';
                $params[] = null;
            } else {
                if (!is_string($displayName)) {
                    Response::error(422, 'INVALID_DISPLAY_NAME', 'display_name must be a string.');
                }
                $displayName = trim($displayName);
                if ($displayName === '' || mb_strlen($displayName) > 50) {
                    Response::error(422, 'INVALID_DISPLAY_NAME', 'display_name must contain 1–50 characters.');
                }
                $updates[] = 'display_name = ?';
                $params[] = $displayName;
            }
        }

        if (array_key_exists('avatar_url', $input)) {
            $avatarUrl = $input['avatar_url'];
            if ($avatarUrl === null || $avatarUrl === '') {
                $updates[] = 'avatar_url = ?';
                $params[] = null;
            } else {
                if (!is_string($avatarUrl)) {
                    Response::error(422, 'INVALID_AVATAR_URL', 'avatar_url must be a string.');
                }
                $avatarUrl = trim($avatarUrl);
                if (mb_strlen($avatarUrl) > 500) {
                    Response::error(422, 'INVALID_AVATAR_URL', 'avatar_url must be at most 500 characters.');
                }
                if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
                    Response::error(422, 'INVALID_AVATAR_URL', 'avatar_url must be a valid URL.');
                }
                $updates[] = 'avatar_url = ?';
                $params[] = $avatarUrl;
            }
        }

        if (array_key_exists('bio', $input)) {
            $bio = $input['bio'];
            if ($bio === null || $bio === '') {
                $updates[] = 'bio = ?';
                $params[] = null;
            } else {
                if (!is_string($bio)) {
                    Response::error(422, 'INVALID_BIO', 'bio must be a string.');
                }
                $bio = trim($bio);
                if (mb_strlen($bio) > 250) {
                    Response::error(422, 'INVALID_BIO', 'bio must be at most 250 characters.');
                }
                $updates[] = 'bio = ?';
                $params[] = $bio;
            }
        }

        if (empty($updates)) {
            Response::error(422, 'NO_VALID_FIELDS', 'No valid updatable fields provided.');
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?';
        $params[] = $userId;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error(409, 'USERNAME_UNAVAILABLE', 'That username may already be in use.');
            }
            error_log('Profile update error: ' . $e->getMessage());
            Response::error(500, 'PROFILE_UPDATE_FAILED', 'Could not update profile.');
        }

        // Fetch updated profile
        $stmt = $pdo->prepare(
            'SELECT u.id, u.public_id, u.account_role, u.status,
                    p.username, p.display_name, p.avatar_url, p.bio, p.updated_at
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        Response::ok([
            'user' => [
                'id' => (int)$profile['id'],
                'public_id' => $profile['public_id'],
                'account_role' => $profile['account_role'],
                'status' => $profile['status'],
            ],
            'profile' => [
                'username' => $profile['username'],
                'display_name' => $profile['display_name'],
                'avatar_url' => $profile['avatar_url'],
                'bio' => $profile['bio'],
                'updated_at' => $profile['updated_at'],
            ],
        ]);
    }
}
