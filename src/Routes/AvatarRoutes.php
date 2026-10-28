<?php
declare(strict_types=1);

final class AvatarRoutes
{
    public static function index(PDO $pdo): never
    {
        Http::method('GET');
        $stmt = $pdo->query('SELECT id, filename, mime_type, image_data FROM anime_avatars ORDER BY id');
        $avatars = [];
        foreach ($stmt->fetchAll() as $row) {
            $avatars[] = [
                'id' => (int)$row['id'],
                'filename' => $row['filename'],
                'mime_type' => $row['mime_type'],
                'image_data' => 'data:' . $row['mime_type'] . ';base64,' . base64_encode($row['image_data']),
            ];
        }
        Response::ok(['avatars' => $avatars]);
    }
}
