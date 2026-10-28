<?php
declare(strict_types=1);

final class ConversationRoutes
{
    public static function dispatch(PDO $pdo): never
    {
        Http::method('GET');
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];

        // Bound the page size so this endpoint stays cheap even if a client sends
        // an accidentally large limit. `before_id` provides stable keyset paging.
        $limit = 20;
        if (isset($_GET['limit'])) {
            $rawLimit = $_GET['limit'];
            if (!is_string($rawLimit) || !ctype_digit($rawLimit) || (int)$rawLimit < 1) {
                Response::error(422, 'INVALID_LIMIT', 'limit must be a positive integer.');
            }
            $limit = min((int)$rawLimit, 100);
        }

        $beforeId = null;
        if (isset($_GET['before_id'])) {
            $rawBeforeId = $_GET['before_id'];
            if (!is_string($rawBeforeId) || !ctype_digit($rawBeforeId) || (int)$rawBeforeId < 1) {
                Response::error(422, 'INVALID_BEFORE_ID', 'before_id must be a positive integer.');
            }
            $beforeId = (int)$rawBeforeId;
        }

        $sql = "SELECT c.id, c.type, c.status, c.created_at, c.ended_at,
                       (SELECT MAX(m.id) FROM messages m
                        WHERE m.conversation_id = c.id AND m.deleted_at IS NULL) AS last_message_id
                FROM conversations c
                JOIN conversation_participants mine
                  ON mine.conversation_id = c.id AND mine.user_id = ? AND mine.left_at IS NULL
                WHERE (? IS NULL OR c.id < ?)
                ORDER BY c.id DESC
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $beforeId, $beforeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(3, $beforeId, $beforeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(4, $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $conversations = [];
        foreach ($rows as $row) {
            $conversationId = (int)$row['id'];

            $participantsQuery = $pdo->prepare(
                'SELECT u.public_id, p.display_name, p.avatar_url, p.avatar_id
                 FROM conversation_participants cp
                 JOIN users u ON u.id = cp.user_id
                 LEFT JOIN profiles p ON p.user_id = u.id
                 WHERE cp.conversation_id = ? AND cp.user_id <> ? AND cp.left_at IS NULL
                 ORDER BY cp.joined_at ASC'
            );
            $participantsQuery->execute([$conversationId, $userId]);
            $participants = array_map(static function (array $participant): array {
                return [
                    'public_id' => $participant['public_id'],
                    'display_name' => $participant['display_name'],
                    'avatar_url' => $participant['avatar_url'],
                    'avatar_id' => $participant['avatar_id'] !== null ? (int)$participant['avatar_id'] : null,
                ];
            }, $participantsQuery->fetchAll());

            $lastMessage = null;
            if ($row['last_message_id'] !== null) {
                $messageQuery = $pdo->prepare(
                    'SELECT m.id, m.sender_id, u.public_id AS sender_public_id,
                            m.message_type, m.content, m.media_url, m.created_at
                     FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?'
                );
                $messageQuery->execute([(int)$row['last_message_id']]);
                $message = $messageQuery->fetch();
                if ($message) {
                    $lastMessage = [
                        'id' => (int)$message['id'],
                        'sender_public_id' => $message['sender_public_id'],
                        'is_mine' => (int)$message['sender_id'] === $userId,
                        'type' => $message['message_type'],
                        'content' => $message['content'],
                        'media_url' => $message['media_url'],
                        'created_at' => $message['created_at'],
                    ];
                }
            }

            $conversations[] = [
                'id' => $conversationId,
                'type' => $row['type'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'ended_at' => $row['ended_at'],
                'participants' => $participants,
                'last_message' => $lastMessage,
            ];
        }

        $nextBeforeId = $hasMore && count($rows) > 0 ? (int)$rows[count($rows) - 1]['id'] : null;
        Response::ok([
            'conversations' => $conversations,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_before_id' => $nextBeforeId,
            ],
        ]);
    }
}
