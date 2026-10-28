<?php
declare(strict_types=1);

final class InboxRoutes
{
    public static function dispatch(PDO $pdo, string $path): never
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($path === '/api/inbox/links') {
            if ($method === 'GET') {
                self::links($pdo);
            }
            if ($method === 'POST') {
                self::createLink($pdo);
            }
            header('Allow: GET, POST');
            Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
        }

        if ($path === '/api/inbox/messages') {
            if ($method === 'GET') {
                self::messages($pdo);
            }
            header('Allow: GET');
            Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
        }

        if (preg_match('#^/api/inbox/links/([A-Za-z0-9_-]+)/messages$#', $path, $matches)) {
            if ($method !== 'POST') {
                header('Allow: POST');
                Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
            }
            self::sendMessage($pdo, $matches[1]);
        }

        if (preg_match('#^/api/inbox/messages/(\d+)$#', $path, $matches)) {
            if ($method !== 'PATCH') {
                header('Allow: PATCH');
                Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
            }
            self::updateMessage($pdo, (int)$matches[1]);
        }

        if (preg_match('#^/api/inbox/messages/(\d+)/replies$#', $path, $matches)) {
            if ($method !== 'POST') {
                header('Allow: POST');
                Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed.');
            }
            self::reply($pdo, (int)$matches[1]);
        }

        Response::error(404, 'NOT_FOUND', 'API endpoint not found.');
    }

    private static function links(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, slug, title, is_active, created_at FROM anonymous_links
             WHERE owner_user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$auth['user']['id']]);
        $links = array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
        ], $stmt->fetchAll());
        Response::ok(['links' => $links]);
    }

    private static function createLink(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $input = Http::body();
        $title = $input['title'] ?? null;
        if ($title !== null) {
            if (!is_string($title)) {
                Response::error(422, 'INVALID_TITLE', 'title must be a string or null.');
            }
            $title = trim($title);
            if ($title === '') {
                $title = null;
            } elseif (mb_strlen($title) > 100) {
                Response::error(422, 'INVALID_TITLE', 'title must be at most 100 characters.');
            }
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $slug = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            try {
                $stmt = $pdo->prepare('INSERT INTO anonymous_links (owner_user_id, slug, title) VALUES (?, ?, ?)');
                $stmt->execute([$auth['user']['id'], $slug, $title]);
                Response::ok(['link' => [
                    'id' => (int)$pdo->lastInsertId(),
                    'slug' => $slug,
                    'title' => $title,
                    'is_active' => true,
                ]], 201);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
            }
        }
        Response::error(500, 'LINK_CREATE_FAILED', 'Could not create an inbox link.');
    }

    private static function sendMessage(PDO $pdo, string $slug): never
    {
        $input = Http::body();
        $content = $input['content'] ?? null;
        if (!is_string($content)) {
            Response::error(422, 'INVALID_CONTENT', 'content must be a string.');
        }
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 5000) {
            Response::error(422, 'INVALID_CONTENT', 'content must contain 1 to 5000 characters.');
        }

        $link = $pdo->prepare('SELECT id FROM anonymous_links WHERE slug = ? AND is_active = 1 LIMIT 1');
        $link->execute([$slug]);
        $linkId = $link->fetchColumn();
        if ($linkId === false) {
            Response::error(404, 'LINK_NOT_FOUND', 'Inbox link not found.');
        }

        $stmt = $pdo->prepare("INSERT INTO inbox_messages (anonymous_link_id, content, status) VALUES (?, ?, 'unread')");
        $stmt->execute([(int)$linkId, $content]);
        Response::ok(['message' => [
            'id' => (int)$pdo->lastInsertId(),
            'status' => 'unread',
            'created_at' => date('Y-m-d H:i:s'),
        ]], 201);
    }

    private static function messages(PDO $pdo): never
    {
        $auth = Auth::requireAuth($pdo);
        $limit = 20;
        if (isset($_GET['limit'])) {
            $raw = $_GET['limit'];
            if (!is_string($raw) || !ctype_digit($raw) || (int)$raw < 1) {
                Response::error(422, 'INVALID_LIMIT', 'limit must be a positive integer.');
            }
            $limit = min((int)$raw, 100);
        }
        $beforeId = null;
        if (isset($_GET['before_id'])) {
            $raw = $_GET['before_id'];
            if (!is_string($raw) || !ctype_digit($raw) || (int)$raw < 1) {
                Response::error(422, 'INVALID_BEFORE_ID', 'before_id must be a positive integer.');
            }
            $beforeId = (int)$raw;
        }

        $stmt = $pdo->prepare(
            'SELECT m.id, m.content, m.status, m.created_at, m.sender_user_id,
                    l.id AS link_id, l.slug, l.title,
                    u.public_id AS sender_public_id, p.display_name AS sender_display_name
             FROM inbox_messages m
             JOIN anonymous_links l ON l.id = m.anonymous_link_id
             LEFT JOIN users u ON u.id = m.sender_user_id
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE l.owner_user_id = ? AND (? IS NULL OR m.id < ?)
             ORDER BY m.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $auth['user']['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $beforeId, $beforeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(3, $beforeId, $beforeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(4, $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $messages = array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'content' => $row['content'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'sender' => $row['sender_user_id'] === null ? null : [
                'public_id' => $row['sender_public_id'],
                'display_name' => $row['sender_display_name'],
            ],
            'link' => ['id' => (int)$row['link_id'], 'slug' => $row['slug'], 'title' => $row['title']],
        ], $rows);
        Response::ok([
            'messages' => $messages,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_before_id' => $hasMore && count($rows) > 0 ? (int)$rows[count($rows) - 1]['id'] : null,
            ],
        ]);
    }

    private static function updateMessage(PDO $pdo, int $messageId): never
    {
        $auth = Auth::requireAuth($pdo);
        $input = Http::body();
        $status = $input['status'] ?? null;
        if (!in_array($status, ['read', 'hidden', 'removed'], true)) {
            Response::error(422, 'INVALID_STATUS', 'status must be read, hidden, or removed.');
        }
        $stmt = $pdo->prepare(
            'UPDATE inbox_messages m JOIN anonymous_links l ON l.id = m.anonymous_link_id
             SET m.status = ? WHERE m.id = ? AND l.owner_user_id = ?'
        );
        $stmt->execute([$status, $messageId, $auth['user']['id']]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT m.status FROM inbox_messages m JOIN anonymous_links l ON l.id = m.anonymous_link_id WHERE m.id = ? AND l.owner_user_id = ?');
            $check->execute([$messageId, $auth['user']['id']]);
            if ($check->fetchColumn() === false) {
                Response::error(404, 'MESSAGE_NOT_FOUND', 'Inbox message not found.');
            }
        }
        Response::ok(['id' => $messageId, 'status' => $status]);
    }

    private static function reply(PDO $pdo, int $messageId): never
    {
        $auth = Auth::requireAuth($pdo);
        $input = Http::body();
        $content = $input['content'] ?? null;
        if (!is_string($content)) {
            Response::error(422, 'INVALID_CONTENT', 'content must be a string.');
        }
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 5000) {
            Response::error(422, 'INVALID_CONTENT', 'content must contain 1 to 5000 characters.');
        }
        $visibility = $input['visibility'] ?? 'private';
        if (!in_array($visibility, ['private', 'public'], true)) {
            Response::error(422, 'INVALID_VISIBILITY', 'visibility must be private or public.');
        }

        $pdo->beginTransaction();
        try {
            $owner = $pdo->prepare(
                'SELECT m.id FROM inbox_messages m JOIN anonymous_links l ON l.id = m.anonymous_link_id
                 WHERE m.id = ? AND l.owner_user_id = ? FOR UPDATE'
            );
            $owner->execute([$messageId, $auth['user']['id']]);
            if ($owner->fetchColumn() === false) {
                $pdo->rollBack();
                Response::error(404, 'MESSAGE_NOT_FOUND', 'Inbox message not found.');
            }
            $reply = $pdo->prepare('INSERT INTO inbox_replies (inbox_message_id, user_id, content, visibility) VALUES (?, ?, ?, ?)');
            $reply->execute([$messageId, $auth['user']['id'], $content, $visibility]);
            $pdo->prepare("UPDATE inbox_messages SET status = 'replied' WHERE id = ?")->execute([$messageId]);
            $replyId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Response::ok(['reply' => [
            'id' => $replyId,
            'inbox_message_id' => $messageId,
            'content' => $content,
            'visibility' => $visibility,
        ]], 201);
    }
}
