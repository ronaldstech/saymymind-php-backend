<?php
declare(strict_types=1);

final class SafetyRoutes
{
    public static function dispatch(PDO $pdo, string $path): never
    {
        if ($path === '/api/blocks') {
            Http::method('POST');
            self::blockUser($pdo);
        }
        if ($path === '/api/reports') {
            Http::method('POST');
            self::reportUser($pdo);
        }
        Response::error(404, 'NOT_FOUND', 'API endpoint not found.');
    }

    private static function blockUser(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        $publicId = $input['public_id'] ?? null;
        if (!is_string($publicId) || trim($publicId) === '') {
            Response::error(422, 'INVALID_PUBLIC_ID', 'public_id is required.');
        }
        $lookup = $pdo->prepare("SELECT id, public_id FROM users WHERE public_id = ? AND status = 'active' LIMIT 1");
        $lookup->execute([trim($publicId)]);
        $target = $lookup->fetch();
        if (!$target) Response::error(404, 'USER_NOT_FOUND', 'That user could not be found.');
        $targetId = (int)$target['id'];
        if ($targetId === $userId) Response::error(422, 'CANNOT_BLOCK_SELF', 'You cannot block yourself.');

        $conversationEnded = false;
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_user_id, blocked_user_id) VALUES (?, ?)');
            $insert->execute([$userId, $targetId]);
            $newlyBlocked = $insert->rowCount() > 0;
            $pdo->prepare('DELETE FROM user_friends WHERE (user_id = ? AND friend_user_id = ?) OR (user_id = ? AND friend_user_id = ?)')
                ->execute([$userId, $targetId, $targetId, $userId]);
            $endConversations = $pdo->prepare(
                "UPDATE conversations c
                 JOIN conversation_participants mine
                   ON mine.conversation_id = c.id AND mine.user_id = ?
                 JOIN conversation_participants other
                   ON other.conversation_id = c.id AND other.user_id = ?
                 SET c.status = 'ended', c.ended_at = COALESCE(c.ended_at, NOW())
                 WHERE c.status = 'active'"
            );
            $endConversations->execute([$userId, $targetId]);
            $conversationEnded = $endConversations->rowCount() > 0;
            $pdo->prepare(
                "UPDATE matches SET status = 'ended', ended_at = COALESCE(ended_at, NOW())
                 WHERE status = 'active' AND ((user_a_id = ? AND user_b_id = ?) OR (user_a_id = ? AND user_b_id = ?))"
            )->execute([$userId, $targetId, $targetId, $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::ok([
            'blocked' => true,
            'already_blocked' => !$newlyBlocked,
            'conversation_ended' => $conversationEnded,
            'public_id' => $target['public_id'],
        ]);
    }

    private static function reportUser(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = (int)$auth['user']['id'];
        $input = Http::body();
        $publicId = $input['public_id'] ?? null;
        $reason = $input['reason'] ?? null;
        $description = $input['description'] ?? null;
        if (!is_string($publicId) || trim($publicId) === '') {
            Response::error(422, 'INVALID_PUBLIC_ID', 'public_id is required.');
        }
        if (!is_string($reason) || !in_array($reason, ['harassment', 'spam', 'inappropriate_content', 'other'], true)) {
            Response::error(422, 'INVALID_REPORT_REASON', 'Choose a valid report reason.');
        }
        if ($description !== null && (!is_string($description) || mb_strlen(trim($description)) > 1000)) {
            Response::error(422, 'INVALID_REPORT_DESCRIPTION', 'description must be 1000 characters or fewer.');
        }
        $lookup = $pdo->prepare("SELECT id FROM users WHERE public_id = ? AND status = 'active' LIMIT 1");
        $lookup->execute([trim($publicId)]);
        $targetId = $lookup->fetchColumn();
        if ($targetId === false) Response::error(404, 'USER_NOT_FOUND', 'That user could not be found.');
        if ((int)$targetId === $userId) Response::error(422, 'CANNOT_REPORT_SELF', 'You cannot report yourself.');

        $stmt = $pdo->prepare('INSERT INTO reports (reporter_user_id, reported_user_id, reason, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, (int)$targetId, $reason, $description === null ? null : trim($description)]);
        Response::ok(['report_id' => (int)$pdo->lastInsertId(), 'status' => 'pending'], 201);
    }
}