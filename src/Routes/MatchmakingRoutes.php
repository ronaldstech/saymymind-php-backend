<?php
declare(strict_types=1);

final class MatchmakingRoutes
{
    public static function dispatch(PDO $pdo): never
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST') {
            self::queue($pdo);
        }
        if ($method === 'GET') {
            self::status($pdo);
        }
        if ($method === 'DELETE') {
            self::cancel($pdo);
        }
        header('Allow: GET, POST, DELETE');
        Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
    }

    private static function queue(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];

        try {
            $pdo->beginTransaction();

            $settings = $pdo->prepare('SELECT allow_matching FROM user_settings WHERE user_id = ? FOR UPDATE');
            $settings->execute([$userId]);
            if (!(bool)$settings->fetchColumn()) {
                $pdo->rollBack();
                Response::error(403, 'MATCHING_DISABLED', 'Matching is disabled in your settings.');
            }

            $active = self::findActiveMatch($pdo, $userId);
            if ($active !== null) {
                $pdo->commit();
                Response::ok(['status' => 'matched', 'match' => $active]);
            }

            // Reuse the user's one queue row after an earlier match has ended or was cancelled.
            $upsert = $pdo->prepare(
                "INSERT INTO match_queue_entries (user_id, status, queued_at)
                 VALUES (?, 'waiting', NOW())
                 ON DUPLICATE KEY UPDATE status = 'waiting', queued_at = NOW()"
            );
            $upsert->execute([$userId]);

            // Lock the oldest eligible peer. Locking the row prevents another request from
            // consuming the same waiting user while this transaction creates the match.
            $peerQuery = $pdo->prepare(
                "SELECT q.user_id
                 FROM match_queue_entries q
                 JOIN user_settings s ON s.user_id = q.user_id AND s.allow_matching = 1
                 WHERE q.status = 'waiting' AND q.user_id <> ?
                   AND NOT EXISTS (
                       SELECT 1 FROM user_blocks b
                       WHERE (b.blocker_user_id = ? AND b.blocked_user_id = q.user_id)
                          OR (b.blocker_user_id = q.user_id AND b.blocked_user_id = ?)
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM matches m
                       WHERE m.status = 'active'
                         AND (m.user_a_id = q.user_id OR m.user_b_id = q.user_id)
                   )
                 ORDER BY q.queued_at ASC, q.user_id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $peerQuery->execute([$userId, $userId, $userId]);
            $peerId = $peerQuery->fetchColumn();

            if ($peerId === false) {
                $pdo->commit();
                Response::ok(['status' => 'waiting', 'match' => null], 202);
            }
            $peerId = (int)$peerId;

            $conversation = $pdo->prepare("INSERT INTO conversations (type, status) VALUES ('random', 'active')");
            $conversation->execute();
            $conversationId = (int)$pdo->lastInsertId();

            $userA = min($userId, $peerId);
            $userB = max($userId, $peerId);
            $matchInsert = $pdo->prepare(
                "INSERT INTO matches (user_a_id, user_b_id, conversation_id, status)
                 VALUES (?, ?, ?, 'active')"
            );
            $matchInsert->execute([$userA, $userB, $conversationId]);
            $matchId = (int)$pdo->lastInsertId();

            $participants = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)');
            $participants->execute([$conversationId, $userA, $conversationId, $userB]);

            $markMatched = $pdo->prepare("UPDATE match_queue_entries SET status = 'matched' WHERE user_id IN (?, ?)");
            $markMatched->execute([$userA, $userB]);

            $pdo->commit();
            Response::ok([
                'status' => 'matched',
                'match' => [
                    'id' => $matchId,
                    'conversation_id' => $conversationId,
                    'partner' => self::publicUser($pdo, $peerId),
                    'matched_at' => date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Matchmaking queue error: ' . $e->getMessage());
            Response::error(500, 'MATCHMAKING_FAILED', 'Could not join the matching queue.');
        }
    }

    private static function status(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];
        $match = self::findActiveMatch($pdo, $userId);
        if ($match !== null) {
            Response::ok(['status' => 'matched', 'match' => $match]);
        }

        $stmt = $pdo->prepare('SELECT status, queued_at FROM match_queue_entries WHERE user_id = ?');
        $stmt->execute([$userId]);
        $entry = $stmt->fetch();
        if (!$entry || $entry['status'] !== 'waiting') {
            Response::ok(['status' => 'idle', 'match' => null]);
        }
        Response::ok(['status' => 'waiting', 'queued_at' => $entry['queued_at'], 'match' => null]);
    }

    private static function cancel(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $stmt = $pdo->prepare(
            "UPDATE match_queue_entries SET status = 'cancelled'
             WHERE user_id = ? AND status = 'waiting'"
        );
        $stmt->execute([$auth['user']['id']]);
        Response::ok(['cancelled' => $stmt->rowCount() > 0]);
    }

    private static function findActiveMatch(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.conversation_id, m.matched_at,
                    CASE WHEN m.user_a_id = ? THEN m.user_b_id ELSE m.user_a_id END AS partner_id
             FROM matches m
             JOIN conversations c ON c.id = m.conversation_id AND c.status = 'active'
             WHERE m.status = 'active' AND (m.user_a_id = ? OR m.user_b_id = ?)
             ORDER BY m.matched_at DESC LIMIT 1"
        );
        $stmt->execute([$userId, $userId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'partner' => self::publicUser($pdo, (int)$row['partner_id']),
            'matched_at' => $row['matched_at'],
        ];
    }

    private static function publicUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.public_id, p.username, p.display_name, p.avatar_url, p.avatar_id
             FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch() ?: [];
        return [
            'public_id' => $user['public_id'] ?? null,
            'username' => $user['username'] ?? null,
            'display_name' => $user['display_name'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'avatar_id' => isset($user['avatar_id']) ? (int)$user['avatar_id'] : null,
        ];
    }
}
