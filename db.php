<?php

require_once __DIR__ . '/logger.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$dbPath, $needInit] = resolveDatabasePath();

    log_info('Инициализация подключения к базе данных', [
        'path' => $dbPath,
        'new_database' => $needInit,
    ]);

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
    } catch (PDOException $e) {
        log_error('Не удалось подключиться к базе данных', [
            'path' => $dbPath,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initializeDatabase($pdo, $needInit);

    return $pdo;
}

function resolveDatabasePath(): array
{
    $customPath = trim((string) (getenv('APP_DB_PATH') ?: ''));

    if ($customPath !== '') {
        if (!preg_match('/^(?:[a-zA-Z]:\\\\|\\\\\\\\|\/)/', $customPath)) {
            $customPath = __DIR__ . DIRECTORY_SEPARATOR . $customPath;
        }

        $directory = dirname($customPath);
        if (!ensureDirectoryWritable($directory)) {
            log_error('Каталог для базы данных (APP_DB_PATH) недоступен для записи', [
                'directory' => $directory,
            ]);
            throw new RuntimeException('Каталог для базы данных (APP_DB_PATH) недоступен для записи: ' . $directory);
        }

        log_info('Используется пользовательский путь для базы данных', [
            'path' => $customPath,
        ]);
        return [$customPath, !file_exists($customPath)];
    }

    $directories = [
        __DIR__ . DIRECTORY_SEPARATOR . 'data',
        __DIR__ . DIRECTORY_SEPARATOR . 'App_Data',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'work-calendar',
    ];

    foreach ($directories as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . 'app.db';
        if (file_exists($path) && ensureDirectoryWritable($dir)) {
            log_info('Обнаружена существующая база данных', [
                'path' => $path,
            ]);
            return [$path, false];
        }
    }

    foreach ($directories as $dir) {
        if (!ensureDirectoryWritable($dir)) {
            log_warning('Каталог недоступен для записи при подготовке базы данных', [
                'directory' => $dir,
            ]);
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'app.db';
        log_info('Подготовлен путь для новой базы данных', [
            'path' => $path,
        ]);
        return [$path, !file_exists($path)];
    }

    log_error('Не удалось подготовить каталог для базы данных', []);
    throw new RuntimeException('Не удалось подготовить каталог для базы данных. Проверьте права на запись или задайте переменную окружения APP_DB_PATH.');
}

function ensureDirectoryWritable(string $dir): bool
{
    $dir = rtrim($dir, "\\/");

    if ($dir === '') {
        return false;
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            log_warning('Не удалось создать каталог для базы данных', [
                'directory' => $dir,
            ]);
            return false;
        }
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }

    if (!is_writable($dir)) {
        $testFile = $dir . DIRECTORY_SEPARATOR . '.permissions_test';
        if (@file_put_contents($testFile, 'ok') === false) {
            log_warning('Каталог недоступен для записи', [
                'directory' => $dir,
            ]);
            return false;
        }
        @unlink($testFile);
    }

    return true;
}

function initializeDatabase(PDO $pdo, bool $isNew): void
{
    log_info('Проверка структуры базы данных', [
        'is_new' => $isNew,
    ]);
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
        log_info('Создаётся новая база данных, выполняется заполнение данными по умолчанию');
        seedParticipants($pdo);
    } else {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
        if ($count === 0) {
            log_info('База данных пуста, выполняется заполнение данными по умолчанию');
            seedParticipants($pdo);
        }
    }
}

function seedParticipants(PDO $pdo): void
{
    $defaults = ['Иванов', 'Петров', 'Сидоров'];
    log_info('Первичное заполнение участников', [
        'participants' => $defaults,
    ]);
    $stmt = $pdo->prepare('INSERT INTO participants (name, sort_order) VALUES (:name, :sort)');
    foreach ($defaults as $index => $name) {
        $stmt->execute([
            ':name' => $name,
            ':sort' => $index,
        ]);
    }
    log_info('Первичное заполнение участников завершено', [
        'count' => count($defaults),
    ]);
}
