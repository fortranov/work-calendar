<?php

const LOG_LEVEL_INFO = 'INFO';
const LOG_LEVEL_WARNING = 'WARNING';
const LOG_LEVEL_ERROR = 'ERROR';

function log_info(string $message, array $context = []): void
{
    log_message(LOG_LEVEL_INFO, $message, $context);
}

function log_warning(string $message, array $context = []): void
{
    log_message(LOG_LEVEL_WARNING, $message, $context);
}

function log_error(string $message, array $context = []): void
{
    log_message(LOG_LEVEL_ERROR, $message, $context);
}

function log_message(string $level, string $message, array $context = []): void
{
    $timestamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $line = sprintf('[%s] %s: %s', $timestamp, strtoupper($level), $message);

    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $line .= ' ' . $encoded;
        }
    }

    $path = resolve_log_path();

    if ($path === null) {
        error_log($line);
        return;
    }

    $result = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log($line);
    }
}

function resolve_log_path(): ?string
{
    static $cached = false;
    static $path = null;

    if ($cached) {
        return $path;
    }

    $cached = true;

    $customPath = trim((string) (getenv('APP_LOG_PATH') ?: ''));
    if ($customPath !== '') {
        if (!preg_match('/^(?:[a-zA-Z]:\\\\|\\\\\\\\|\/)/', $customPath)) {
            $customPath = __DIR__ . DIRECTORY_SEPARATOR . $customPath;
        }
        $dir = dirname($customPath);
        if (ensure_log_directory($dir)) {
            $path = $customPath;
            return $path;
        }
    }

    $directories = [
        __DIR__ . DIRECTORY_SEPARATOR . 'data',
        __DIR__ . DIRECTORY_SEPARATOR . 'App_Data',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'work-calendar',
    ];

    foreach ($directories as $dir) {
        if (!ensure_log_directory($dir)) {
            continue;
        }
        $path = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'app.log';
        return $path;
    }

    $path = null;
    return null;
}

function ensure_log_directory(string $dir): bool
{
    $dir = rtrim($dir, '\\/');
    if ($dir === '') {
        return false;
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }

    if (!is_writable($dir)) {
        $testFile = $dir . DIRECTORY_SEPARATOR . '.log_permissions_test';
        if (@file_put_contents($testFile, 'ok') === false) {
            return false;
        }
        @unlink($testFile);
    }

    return true;
}
