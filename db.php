<?php
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'app.db';
    $needInit = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initializeDatabase($pdo, $needInit);

    return $pdo;
}

function initializeDatabase(PDO $pdo, bool $isNew): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )'
    );

    if ($isNew) {
        seedParticipants($pdo);
    } else {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
        if ($count === 0) {
            seedParticipants($pdo);
        }
    }
}

function seedParticipants(PDO $pdo): void
{
    $defaults = ['Иванов', 'Петров', 'Сидоров'];
    $stmt = $pdo->prepare('INSERT INTO participants (name, sort_order) VALUES (:name, :sort)');
    foreach ($defaults as $index => $name) {
        $stmt->execute([
            ':name' => $name,
            ':sort' => $index,
        ]);
    }
}
