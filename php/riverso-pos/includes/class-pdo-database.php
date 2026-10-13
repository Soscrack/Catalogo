<?php

declare(strict_types=1);

final class Riverso_POS_Pdo_Database implements Riverso_POS_Database {
    public function __construct(
        private PDO $pdo,
        private string $prefix = 'wp_'
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function dialect(): string {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public function table(string $name): string {
        $this->assert_ident($name);
        return $this->prefix . 'riverso_' . $name;
    }

    public function charset_collate(): string {
        return '';
    }

    public function exec(string $sql): void {
        $this->pdo->exec($sql);
    }

    public function execute(string $sql, array $params = []): void {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize_params($params));
    }

    public function fetch_all(string $sql, array $params = []): array {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize_params($params));
        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function fetch_one(string $sql, array $params = []): ?array {
        $rows = $this->fetch_all($sql, $params);
        return $rows[0] ?? null;
    }

    public function insert(string $table, array $data): int {
        $this->assert_ident($table);
        [$columns, $placeholders, $params] = $this->bindings($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->execute($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params): void {
        $this->assert_ident($table);
        $sets = [];
        $values = [];
        foreach ($data as $column => $value) {
            $this->assert_ident((string) $column);
            if ($value === null) {
                $sets[] = $column . ' = NULL';
                continue;
            }
            $sets[] = $column . ' = ?';
            $values[] = $value;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $this->execute($sql, array_merge($values, $params));
    }

    public function columns(string $table): array {
        $this->assert_ident($table);
        if ($this->dialect() === 'sqlite') {
            $rows = $this->fetch_all('PRAGMA table_info(' . $table . ')');
            return array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }
        $rows = $this->fetch_all('SHOW COLUMNS FROM ' . $table);
        return array_map(static fn (array $row): string => (string) $row['Field'], $rows);
    }

    public function table_exists(string $table): bool {
        $this->assert_ident($table);
        if ($this->dialect() === 'sqlite') {
            $row = $this->fetch_one(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            return $row !== null;
        }
        $row = $this->fetch_one('SHOW TABLES LIKE ?', [$table]);
        return $row !== null;
    }

    public function transaction(callable $callback): mixed {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: list<string>, 1: list<string>, 2: list<mixed>}
     */
    private function bindings(array $data): array {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($data as $column => $value) {
            $this->assert_ident((string) $column);
            $columns[] = (string) $column;
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = '?';
            $params[] = $value;
        }
        return [$columns, $placeholders, $params];
    }

    /**
     * @param list<mixed> $params
     * @return list<mixed>
     */
    private function normalize_params(array $params): array {
        return array_map(static function ($value) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            return $value;
        }, $params);
    }

    private function assert_ident(string $name): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Identificador SQL inválido.');
        }
    }
}
