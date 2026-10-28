<?php
declare(strict_types=1);

// Run once after the avatar migration: php scripts/seed_anime_avatars.php
$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config/config.php. Copy config/config.example.php and configure the database.\n");
    exit(1);
}

require_once $root . '/src/Database.php';
$pdo = Database::connect(require $configPath);
$files = glob($root . '/public/anime_avatars/avatar_*.jpg') ?: [];
sort($files, SORT_STRING);
$stmt = $pdo->prepare(
    'INSERT INTO anime_avatars (filename, mime_type, image_data) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), image_data = VALUES(image_data)'
);

foreach ($files as $file) {
    $data = file_get_contents($file);
    if ($data === false) {
        throw new RuntimeException('Could not read ' . basename($file));
    }
    $stmt->bindValue(1, basename($file), PDO::PARAM_STR);
    $stmt->bindValue(2, 'image/jpeg', PDO::PARAM_STR);
    $stmt->bindValue(3, $data, PDO::PARAM_LOB);
    $stmt->execute();
}

echo 'Imported ' . count($files) . " anime avatars.\n";
