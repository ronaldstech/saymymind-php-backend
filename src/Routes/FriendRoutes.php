<?php
declare(strict_types=1);

final class FriendRoutes
{
    public static function dispatch(PDO $pdo): never
    {
        Http::method('POST');
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        $publicId = $input['public_id'] ?? null;
        if (!is_string($publicId) || trim($publicId) === '') {
            Response::error(422, 'INVALID_PUBLIC_ID', 'public_id is required.');
        }
        $publicId = trim($publicId);

        $lookup = $pdo->prepare("SELECT id, public_id FROM users WHERE public_id = ? AND status = 'active' LIMIT 1");
        $lookup->execute([$publicId]);
        $friend = $lookup->fetch();
        if (!$friend) {
            Response::error(404, 'USER_NOT_FOUND', 'That user could not be found.');
        }
        $friendId = (int)$friend['id'];
        if ($friendId === $userId) {
            Response::error(422, 'CANNOT_ADD_SELF', 'You cannot add yourself as a friend.');
        }

        $blocked = $pdo->prepare(
            'SELECT 1 FROM user_blocks WHERE (blocker_user_id = ? AND blocked_user_id = ?)
             OR (blocker_user_id = ? AND blocked_user_id = ?) LIMIT 1'
        );
        $blocked->execute([$userId, $friendId, $friendId, $userId]);
        if ($blocked->fetchColumn() !== false) {
            Response::error(403, 'FRIEND_UNAVAILABLE', 'This friend cannot be added.');
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT IGNORE INTO user_friends (user_id, friend_user_id) VALUES (?, ?)');
            $insert->execute([$userId, $friendId]);
            $added = $insert->rowCount() > 0;
            $insert->execute([$friendId, $userId]);
            $added = $added || $insert->rowCount() > 0;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Response::ok([
            'friend' => ['public_id' => $friend['public_id']],
            'is_friend' => true,
            'added' => $added,
        ], $added ? 201 : 200);
    }
}