<?php
declare(strict_types=1);

final class ConversationRoutes
{
    public static function dispatch(PDO $pdo, string $path = '/api/conversations'): never
    {
        if (preg_match('#^/api/conversations/(\d+)/messages$#', $path, $matches)) {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if ($method === 'GET') {
                self::messages($pdo, (int)$matches[1]);
            }
            if ($method === 'POST') {
                self::sendMessage($pdo, (int)$matches[1]);
            }
            header('Allow: GET, POST');
            Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
        }

        if ($path !== '/api/conversations') {
            Response::error(404, 'NOT_FOUND', 'API endpoint not found.');
        }
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
                'SELECT u.id, u.public_id, p.username, p.display_name, p.avatar_url, p.avatar_id
                 FROM conversation_participants cp
                 JOIN users u ON u.id = cp.user_id
                 LEFT JOIN profiles p ON p.user_id = u.id
                 WHERE cp.conversation_id = ? AND cp.left_at IS NULL
                 ORDER BY cp.joined_at ASC'
            );
            $participantsQuery->execute([$conversationId]);
            $participants = array_map(static function (array $participant) use ($userId): array {
                return [
                    'public_id' => $participant['public_id'],
                    'username' => $participant['username'],
                    'display_name' => $participant['display_name'],
                    'avatar_url' => $participant['avatar_url'],
                    'avatar_id' => $participant['avatar_id'] !== null ? (int)$participant['avatar_id'] : null,
                    'is_mine' => (int)$participant['id'] === $userId,
                ];
            }, $participantsQuery->fetchAll());

            $lastMessage = null;
            if ($row['last_message_id'] !== null) {
                $messageQuery = $pdo->prepare(
                    'SELECT m.id, m.sender_id, u.public_id AS sender_public_id, p.username AS sender_username,
                            m.message_type, m.content, m.media_url, m.created_at
                     FROM messages m JOIN users u ON u.id = m.sender_id
                     LEFT JOIN profiles p ON p.user_id = u.id WHERE m.id = ?'
                );
                $messageQuery->execute([(int)$row['last_message_id']]);
                $message = $messageQuery->fetch();
                if ($message) {
                    $lastMessage = [
                        'id' => (int)$message['id'],
                        'sender_public_id' => $message['sender_public_id'],
                        'sender_username' => $message['sender_username'],
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

    private static function messages(PDO $pdo, int $conversationId): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];
        self::requireParticipant($pdo, $conversationId, $userId);

        $limit = 50;
        if (isset($_GET['limit'])) {
            $raw = $_GET['limit'];
            if (!is_string($raw) || !ctype_digit($raw) || (int)$raw < 1) {
                Response::error(422, 'INVALID_LIMIT', 'limit must be a positive integer.');
            }
            $limit = min((int)$raw, 100);
        }
        $afterId = 0;
        if (isset($_GET['after_id'])) {
            $raw = $_GET['after_id'];
            if (!is_string($raw) || !ctype_digit($raw)) {
                Response::error(422, 'INVALID_AFTER_ID', 'after_id must be a non-negative integer.');
            }
            $afterId = (int)$raw;
        }

        $stmt = $pdo->prepare(
            'SELECT m.id, m.sender_id, u.public_id AS sender_public_id, p.username AS sender_username, m.message_type,
                    m.content, m.media_url, m.reply_to_message_id, m.created_at, m.edited_at,
                    m.deleted_at, r.delivered_at, r.read_at
             FROM messages m
             JOIN users u ON u.id = m.sender_id
             LEFT JOIN profiles p ON p.user_id = u.id
             LEFT JOIN message_receipts r ON r.message_id = m.id AND r.user_id = ?
             WHERE m.conversation_id = ? AND m.id > ?
             ORDER BY m.id ASC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(3, $afterId, PDO::PARAM_INT);
        $stmt->bindValue(4, $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $messages = array_map(static function (array $row) use ($userId): array {
            return [
                'id' => (int)$row['id'],
                'sender_public_id' => $row['sender_public_id'],
                'sender_username' => $row['sender_username'],
                'is_mine' => (int)$row['sender_id'] === $userId,
                'type' => $row['message_type'],
                'content' => $row['deleted_at'] === null ? $row['content'] : null,
                'media_url' => $row['deleted_at'] === null ? $row['media_url'] : null,
                'reply_to_message_id' => $row['reply_to_message_id'] !== null ? (int)$row['reply_to_message_id'] : null,
                'created_at' => $row['created_at'],
                'edited_at' => $row['edited_at'],
                'deleted' => $row['deleted_at'] !== null,
                'delivered_at' => $row['delivered_at'],
                'read_at' => $row['read_at'],
            ];
        }, $rows);

        Response::ok([
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_after_id' => count($rows) > 0 ? (int)$rows[count($rows) - 1]['id'] : $afterId,
            ],
        ]);
    }

    private static function sendMessage(PDO $pdo, int $conversationId): never
    {
        $auth = Auth::requireAuth($pdo);
        $userId = $auth['user']['id'];
        self::requireParticipant($pdo, $conversationId, $userId);

        $conversation = $pdo->prepare("SELECT status FROM conversations WHERE id = ? LIMIT 1");
        $conversation->execute([$conversationId]);
        if ($conversation->fetchColumn() !== 'active') {
            Response::error(409, 'CONVERSATION_ENDED', 'Messages cannot be sent to an inactive conversation.');
        }

        $input = Http::body();
        $type = $input['type'] ?? 'text';
        if (!is_string($type) || !in_array($type, ['text', 'image', 'audio'], true)) {
            Response::error(422, 'INVALID_MESSAGE_TYPE', 'type must be text, image, or audio.');
        }
        $content = $input['content'] ?? null;
        $mediaUrl = $input['media_url'] ?? null;
        if ($content !== null && !is_string($content)) {
            Response::error(422, 'INVALID_CONTENT', 'content must be a string or null.');
        }
        if ($mediaUrl !== null && !is_string($mediaUrl)) {
            Response::error(422, 'INVALID_MEDIA_URL', 'media_url must be a string or null.');
        }
        if ($content !== null) {
            $content = trim($content);
            if ($content === '' || mb_strlen($content) > 5000) {
                Response::error(422, 'INVALID_CONTENT', 'content must contain 1 to 5000 characters.');
            }
        }
        if ($mediaUrl !== null) {
            $mediaUrl = trim($mediaUrl);
            if ($mediaUrl === '' || mb_strlen($mediaUrl) > 1000 || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                Response::error(422, 'INVALID_MEDIA_URL', 'media_url must be a valid URL of at most 1000 characters.');
            }
        }
        if (($type === 'text' && ($content === null || $mediaUrl !== null))
            || ($type !== 'text' && $content === null && $mediaUrl === null)) {
            Response::error(422, 'INVALID_MESSAGE', 'Text messages require content; media messages require content or media_url.');
        }

        $replyTo = $input['reply_to_message_id'] ?? null;
        if ($replyTo !== null) {
            if (is_string($replyTo) && ctype_digit($replyTo)) {
                $replyTo = (int)$replyTo;
            }
            if (!is_int($replyTo) || $replyTo < 1) {
                Response::error(422, 'INVALID_REPLY_TO', 'reply_to_message_id must be a positive integer or null.');
            }
            $replyCheck = $pdo->prepare('SELECT id FROM messages WHERE id = ? AND conversation_id = ?');
            $replyCheck->execute([$replyTo, $conversationId]);
            if ($replyCheck->fetchColumn() === false) {
                Response::error(422, 'INVALID_REPLY_TO', 'The referenced message is not in this conversation.');
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO messages (conversation_id, sender_id, message_type, content, media_url, reply_to_message_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$conversationId, $userId, $type, $content, $mediaUrl, $replyTo]);
            $messageId = (int)$pdo->lastInsertId();

            $receipts = $pdo->prepare(
                'INSERT INTO message_receipts (message_id, user_id)
                 SELECT ?, user_id FROM conversation_participants
                 WHERE conversation_id = ? AND user_id <> ? AND left_at IS NULL'
            );
            $receipts->execute([$messageId, $conversationId, $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $profileQuery = $pdo->prepare('SELECT username FROM profiles WHERE user_id = ? LIMIT 1');
        $profileQuery->execute([$userId]);
        $senderUsername = $profileQuery->fetchColumn();

        Response::ok([
            'message' => [
                'id' => $messageId,
                'conversation_id' => $conversationId,
                'sender_public_id' => $auth['user']['public_id'],
                'sender_username' => $senderUsername !== false ? $senderUsername : null,
                'is_mine' => true,
                'type' => $type,
                'content' => $content,
                'media_url' => $mediaUrl,
                'reply_to_message_id' => $replyTo,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    private static function requireParticipant(PDO $pdo, int $conversationId, int $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT c.id FROM conversations c
             JOIN conversation_participants cp ON cp.conversation_id = c.id
             WHERE c.id = ? AND cp.user_id = ? AND cp.left_at IS NULL LIMIT 1'
        );
        $stmt->execute([$conversationId, $userId]);
        if ($stmt->fetchColumn() === false) {
            Response::error(404, 'CONVERSATION_NOT_FOUND', 'Conversation not found.');
        }
    }
}
