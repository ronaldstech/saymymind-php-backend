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
                    p.username, p.display_name, p.avatar_url, p.avatar_id, p.bio, p.gender, p.age, p.country, p.updated_at
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
                'avatar_id' => isset($profile['avatar_id']) ? (int)$profile['avatar_id'] : null,
                'bio' => $profile['bio'] ?? null,
                'gender' => $profile['gender'] ?? null,
                'age' => isset($profile['age']) && $profile['age'] !== null ? (int)$profile['age'] : null,
                'country' => $profile['country'] ?? null,
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

        $updates = [];
        $params = [];

        if (array_key_exists('avatar_id', $input)) {
            $avatarId = $input['avatar_id'];
            if ($avatarId === null || $avatarId === '') {
                $updates[] = 'avatar_id = ?';
                $params[] = null;
            } else {
                if (is_string($avatarId) && ctype_digit($avatarId)) {
                    $avatarId = (int)$avatarId;
                }
                if (!is_int($avatarId) || $avatarId < 1) {
                    Response::error(422, 'INVALID_AVATAR_ID', 'avatar_id must be a positive integer or null.');
                }
                $updates[] = 'avatar_id = ?';
                $params[] = $avatarId;
                $updates[] = 'avatar_url = ?';
                $params[] = null;
            }
        }

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
                $updates[] = 'avatar_id = ?';
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
                $updates[] = 'avatar_id = ?';
                $params[] = null;
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

        if (array_key_exists('gender', $input)) {
            $gender = $input['gender'];
            if ($gender === null || $gender === '') {
                $updates[] = 'gender = ?';
                $params[] = null;
            } else {
                if (!is_string($gender)) {
                    Response::error(422, 'INVALID_GENDER', 'gender must be a string.');
                }
                $allowedGenders = ['male', 'female', 'other', 'prefer_not_to_say'];
                if (!in_array($gender, $allowedGenders, true)) {
                    Response::error(422, 'INVALID_GENDER', 'gender must be one of: male, female, other, prefer_not_to_say.');
                }
                $updates[] = 'gender = ?';
                $params[] = $gender;
            }
        }

        if (array_key_exists('age', $input)) {
            $age = $input['age'];
            if ($age === null || $age === '') {
                $updates[] = 'age = ?';
                $params[] = null;
            } else {
                if (is_string($age) && ctype_digit($age)) {
                    $age = (int)$age;
                }
                if (!is_int($age) && !is_numeric($age)) {
                    Response::error(422, 'INVALID_AGE', 'age must be a number.');
                }
                $age = (int)$age;
                if ($age < 13 || $age > 120) {
                    Response::error(422, 'INVALID_AGE', 'age must be between 13 and 120.');
                }
                $updates[] = 'age = ?';
                $params[] = $age;
            }
        }

        if (array_key_exists('country', $input)) {
            $country = $input['country'];
            if ($country === null || $country === '') {
                $updates[] = 'country = ?';
                $params[] = null;
            } else {
                if (!is_string($country)) {
                    Response::error(422, 'INVALID_COUNTRY', 'country must be a string.');
                }
                $country = trim($country);
                if ($country === '' || mb_strlen($country) > 100) {
                    Response::error(422, 'INVALID_COUNTRY', 'country must contain 1–100 characters.');
                }
                $updates[] = 'country = ?';
                $params[] = $country;
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
                // Could be username unique or age check
                if (stripos($e->getMessage(), 'username') !== false) {
                    Response::error(409, 'USERNAME_UNAVAILABLE', 'That username may already be in use.');
                }
                if (stripos($e->getMessage(), 'age') !== false) {
                    Response::error(422, 'INVALID_AGE', 'age must be between 13 and 120.');
                }
                Response::error(409, 'CONFLICT', 'Could not update profile due to constraint violation.');
            }
            error_log('Profile update error: ' . $e->getMessage());
            Response::error(500, 'PROFILE_UPDATE_FAILED', 'Could not update profile.');
        }

        // Fetch updated profile
        $stmt = $pdo->prepare(
            'SELECT u.id, u.public_id, u.account_role, u.status,
                    p.username, p.display_name, p.avatar_url, p.avatar_id, p.bio, p.gender, p.age, p.country, p.updated_at
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
                'avatar_id' => isset($profile['avatar_id']) ? (int)$profile['avatar_id'] : null,
                'bio' => $profile['bio'],
                'gender' => $profile['gender'],
                'age' => isset($profile['age']) && $profile['age'] !== null ? (int)$profile['age'] : null,
                'country' => $profile['country'],
                'updated_at' => $profile['updated_at'],
            ],
        ]);
    }
}
