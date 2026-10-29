<?php
declare(strict_types=1);

final class FriendRoutes
{
    public static function dispatch(PDO $pdo, string $path): never
    {
        if ($path === '/api/friends/status') {
            Http::method('GET');
            self::status($pdo);
        }
        if ($path === '/api/friends/accept') {
            Http::method('POST');
            self::accept($pdo);
        }
        if ($path === '/api/friends') {
            Http::method('POST');
            self::request($pdo);
        }
        Response::error(404, 'NOT_FOUND', 'API endpoint not found.');
    }

    private static function status(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $friend = self::findTarget($pdo, $userId, $_GET['public_id'] ?? null);
        $friendId = (int)$friend['id'];
        if (self::isBlocked($pdo, $userId, $friendId)) {
            Response::ok(['status' => 'blocked']);
        }
        if (self::isFriend($pdo, $userId, $friendId)) {
            Response::ok(['status' => 'friends']);
        }
        if (self::hasPendingRequest($pdo, $friendId, $userId)) {
            Response::ok(['status' => 'incoming_pending']);
        }
        if (self::hasPendingRequest($pdo, $userId, $friendId)) {
            Response::ok(['status' => 'outgoing_pending']);
        }
        Response::ok(['status' => 'none']);
    }

    private static function request(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        $friend = self::findTarget($pdo, $userId, $input['public_id'] ?? null);
        $friendId = (int)$friend['id'];
        if (self::isBlocked($pdo, $userId, $friendId)) {
            Response::error(403, 'FRIEND_UNAVAILABLE', 'This friend cannot be added.');
        }
        if (self::isFriend($pdo, $userId, $friendId)) {
            Response::ok(['status' => 'friends', 'request_sent' => false]);
        }
        if (self::hasPendingRequest($pdo, $friendId, $userId)) {
            Response::ok(['status' => 'incoming_pending', 'request_sent' => false]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO friend_requests (requester_user_id, recipient_user_id, status)
             VALUES (?, ?, 'pending')
             ON DUPLICATE KEY UPDATE status = 'pending', created_at = NOW(), responded_at = NULL"
        );
        $stmt->execute([$userId, $friendId]);
        Response::ok([
            'status' => 'outgoing_pending',
            'request_sent' => true,
            'friend' => ['public_id' => $friend['public_id']],
        ], 201);
    }

    private static function accept(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        $friend = self::findTarget($pdo, $userId, $input['public_id'] ?? null);
        $friendId = (int)$friend['id'];
        if (self::isBlocked($pdo, $userId, $friendId)) {
            Response::error(403, 'FRIEND_UNAVAILABLE', 'This friend cannot be added.');
        }
        if (self::isFriend($pdo, $userId, $friendId)) {
            Response::ok(['status' => 'friends', 'accepted' => false]);
        }
        if (!self::hasPendingRequest($pdo, $friendId, $userId)) {
            Response::error(404, 'FRIEND_REQUEST_NOT_FOUND', 'There is no pending request from this user.');
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT IGNORE INTO user_friends (user_id, friend_user_id) VALUES (?, ?)');
            $insert->execute([$userId, $friendId]);
            $insert->execute([$friendId, $userId]);

            $existingConversation = $pdo->prepare(
                "SELECT c.id FROM conversations c
                 JOIN conversation_participants mine
                   ON mine.conversation_id = c.id AND mine.user_id = ?
                 JOIN conversation_participants other
                   ON other.conversation_id = c.id AND other.user_id = ?
                 ORDER BY CASE WHEN c.status = 'active' THEN 0 ELSE 1 END, c.id DESC
                 LIMIT 1 FOR UPDATE"
            );
            $existingConversation->execute([$userId, $friendId]);
            $conversationId = $existingConversation->fetchColumn();
            if ($conversationId !== false) {
                $conversationId = (int)$conversationId;
                $pdo->prepare("UPDATE conversations SET type = 'direct', status = 'active', ended_at = NULL WHERE id = ?")
                    ->execute([$conversationId]);
                $pdo->prepare('UPDATE conversation_participants SET left_at = NULL WHERE conversation_id = ? AND user_id IN (?, ?)')
                    ->execute([$conversationId, $userId, $friendId]);
                    $pdo->prepare("UPDATE matches SET status = 'ended', ended_at = COALESCE(ended_at, NOW()) WHERE conversation_id = ? AND status = 'active'")->execute([$conversationId]);
            } else {
                $pdo->prepare("INSERT INTO conversations (type, status) VALUES ('direct', 'active')")
                    ->execute();
                $conversationId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)')
                    ->execute([$conversationId, $userId, $conversationId, $friendId]);
            }
            $update = $pdo->prepare(
                "UPDATE friend_requests SET status = 'accepted', responded_at = NOW()
                 WHERE ((requester_user_id = ? AND recipient_user_id = ?)
                    OR (requester_user_id = ? AND recipient_user_id = ?))
                   AND status = 'pending'"
            );
            $update->execute([$friendId, $userId, $userId, $friendId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::ok(['status' => 'friends', 'accepted' => true, 'conversation_id' => $conversationId]);
    }

    public static function ensureConversation(PDO $pdo, int $userId, int $friendId): int
    {
        $existing = $pdo->prepare(
            "SELECT c.id FROM conversations c
             JOIN conversation_participants mine
               ON mine.conversation_id = c.id AND mine.user_id = ?
             JOIN conversation_participants other
               ON other.conversation_id = c.id AND other.user_id = ?
             ORDER BY CASE WHEN c.type = 'direct' THEN 0 WHEN c.status = 'active' THEN 1 ELSE 2 END, c.id DESC
             LIMIT 1"
        );
        $existing->execute([$userId, $friendId]);
        $conversationId = $existing->fetchColumn();
        if ($conversationId !== false) {
            $conversationId = (int)$conversationId;
            $pdo->prepare("UPDATE conversations SET type = 'direct', status = 'active', ended_at = NULL WHERE id = ?")
                ->execute([$conversationId]);
            $pdo->prepare('UPDATE conversation_participants SET left_at = NULL WHERE conversation_id = ? AND user_id IN (?, ?)')
                ->execute([$conversationId, $userId, $friendId]);
                $pdo->prepare("UPDATE matches SET status = 'ended', ended_at = COALESCE(ended_at, NOW()) WHERE conversation_id = ? AND status = 'active'")->execute([$conversationId]);
            return $conversationId;
        }

        $pdo->prepare("INSERT INTO conversations (type, status) VALUES ('direct', 'active')")
            ->execute();
        $conversationId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)')
            ->execute([$conversationId, $userId, $conversationId, $friendId]);
        return $conversationId;
    }
    private static function findTarget(PDO $pdo, int $userId, mixed $publicId): array
    {
        if (!is_string($publicId) || trim($publicId) === '') {
            Response::error(422, 'INVALID_PUBLIC_ID', 'public_id is required.');
        }
        $stmt = $pdo->prepare("SELECT id, public_id FROM users WHERE public_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([trim($publicId)]);
        $target = $stmt->fetch();
        if (!$target) Response::error(404, 'USER_NOT_FOUND', 'That user could not be found.');
        if ((int)$target['id'] === $userId) {
            Response::error(422, 'CANNOT_ADD_SELF', 'You cannot add yourself as a friend.');
        }
        return $target;
    }

    private static function isBlocked(PDO $pdo, int $userId, int $friendId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM user_blocks WHERE (blocker_user_id = ? AND blocked_user_id = ?)
             OR (blocker_user_id = ? AND blocked_user_id = ?) LIMIT 1'
        );
        $stmt->execute([$userId, $friendId, $friendId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function isFriend(PDO $pdo, int $userId, int $friendId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM user_friends WHERE user_id = ? AND friend_user_id = ? LIMIT 1');
        $stmt->execute([$userId, $friendId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function hasPendingRequest(PDO $pdo, int $requesterId, int $recipientId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM friend_requests WHERE requester_user_id = ? AND recipient_user_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$requesterId, $recipientId]);
        return $stmt->fetchColumn() !== false;
    }
}