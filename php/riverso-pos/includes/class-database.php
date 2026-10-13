<?php
/**
 * Acceso a datos compartido por migraciones y cotizaciones.
 * El prefijo de WordPress se antepone a las tablas riverso_.
 */

declare(strict_types=1);

interface Riverso_POS_Database {
    public function dialect(): string;

    public function table(string $name): string;

    public function charset_collate(): string;

    public function exec(string $sql): void;

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): void;

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetch_all(string $sql, array $params = []): array;

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch_one(string $sql, array $params = []): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int;

    /**
     * @param array<string, mixed> $data
     * @param list<mixed> $params
     */
    public function update(string $table, array $data, string $where, array $params): void;

    /**
     * @return list<string>
     */
    public function columns(string $table): array;

    public function table_exists(string $table): bool;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
