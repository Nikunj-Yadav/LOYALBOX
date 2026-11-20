<?php
declare(strict_types=1);

/** One shared PDO connection. Errors throw exceptions; prepared statements are real (not emulated). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'loyalbox')
        );
        $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");   // same clock as PHP
    }
    return $pdo;
}

/** Run a prepared statement. All user input must go through $params, never into $sql. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function fetch_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function fetch_one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function scalar(string $sql, array $params = []): mixed { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

/** A rule was broken (e.g. "code already used"). Thrown inside a transaction to roll it back. */
final class BusinessException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'rule')
    {
        parent::__construct($message);
    }
}

/**
 * Run $fn inside a transaction. Commits on success, rolls back on ANY exception.
 * Retries a few times if MySQL reports a deadlock (error 1213).
 */
function transaction(callable $fn): mixed
{
    $pdo = db();
    for ($try = 1; ; $try++) {
        try {
            $pdo->beginTransaction();
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1213 && $try < 3) {
                continue;
            }
            throw $e;
        }
    }
}
