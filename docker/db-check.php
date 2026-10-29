<?php

/**
 * Used by the Docker entrypoint: exits 0 when the database in .env accepts a connection,
 * otherwise prints the reason and exits 1.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_DATABASE'] ?? ''
);

try {
    new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 3]);
    exit(0);
} catch (Throwable $e) {
    echo $e->getMessage();
    exit(1);
}
