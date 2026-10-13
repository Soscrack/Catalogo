<?php

declare(strict_types=1);

final class Riverso_POS_Wpdb_Database implements Riverso_POS_Database {
    public function __construct(private object $wpdb) {
    }

    public function dialect(): string {
        return 'mysql';
    }

    public function table(string $name): string {
        $this->assert_ident($name);
        $prefix = isset($this->wpdb->prefix) ? (string) $this->wpdb->prefix : 'wp_';
        return $prefix . 'riverso_' . $name;
    }

    public function charset_collate(): string {
        if (method_exists($this->wpdb, 'get_charset_collate')) {
            return (string) $this->wpdb->get_charset_collate();
        }
        return '';
    }

    public function exec(string $sql): void {
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException($this->error('No se pudo ejecutar SQL.'));
        }
    }

    public function execute(string $sql, array $params = []): void {
        $prepared = $this->prepare($sql, $params);
        $result = $this->wpdb->query($prepared);
        if ($result === false) {
            throw new RuntimeException($this->error('No se pudo ejecutar SQL.'));
        }
    }

    public function fetch_all(string $sql, array $params = []): array {
        $prepared = $this->prepare($sql, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function fetch_one(string $sql, array $params = []): ?array {
        $prepared = $this->prepare($sql, $params);
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function insert(string $table, array $data): int {
        $this->assert_ident($table);
        [$columns, $placeholders, $params] = $this->bindings($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->execute($sql, $params);
        return (int) $this->wpdb->insert_id;
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
        $rows = $this->fetch_all('SHOW COLUMNS FROM ' . $table);
        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $rows);
    }

    public function table_exists(string $table): bool {
        $this->assert_ident($table);
        $row = $this->fetch_one('SHOW TABLES LIKE ?', [$table]);
        return $row !== null;
    }

    public function transaction(callable $callback): mixed {
        $this->exec('START TRANSACTION');
        try {
            $result = $callback();
            $this->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function prepare(string $sql, array $params): string {
        if ($params === []) {
            return $sql;
        }
        $formats = [];
        $values = [];
        foreach ($params as $value) {
            if (is_int($value)) {
                $formats[] = '%d';
                $values[] = $value;
            } elseif (is_float($value)) {
                $formats[] = '%F';
                $values[] = $value;
            } else {
                $formats[] = '%s';
                $values[] = (string) $value;
            }
        }
        $index = 0;
        $converted = preg_replace_callback('/\?/', static function () use (&$index, $formats): string {
            return $formats[$index++];
        }, $sql);
        $prepared = $this->wpdb->prepare($converted, $values);
        if (!is_string($prepared)) {
            throw new RuntimeException('No se pudo preparar la consulta.');
        }
        return $prepared;
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

    private function error(string $fallback): string {
        $message = isset($this->wpdb->last_error) ? (string) $this->wpdb->last_error : '';
        return $message !== '' ? $message : $fallback;
    }

    private function assert_ident(string $name): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Identificador SQL inválido.');
        }
    }
}
