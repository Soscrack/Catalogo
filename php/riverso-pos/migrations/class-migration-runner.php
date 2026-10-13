<?php

declare(strict_types=1);

final class Riverso_POS_Migration_Runner {
    /**
     * @param list<Riverso_POS_Migration_Phase> $phases
     */
    public function __construct(
        private Riverso_POS_Database $db,
        private array $phases
    ) {
    }

    /**
     * @return list<string>
     */
    public function migrate(): array {
        $this->ensure_migrations_table();
        $applied = [];
        foreach ($this->phases as $phase) {
            if ($this->is_applied($phase->id())) {
                continue;
            }
            $phase->up($this->db);
            $this->db->insert($this->migrations_table(), [
                'phase' => $phase->id(),
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $applied[] = $phase->id();
        }
        return $applied;
    }

    public function is_applied(string $phase): bool {
        if (!$this->db->table_exists($this->migrations_table())) {
            return false;
        }
        $row = $this->db->fetch_one(
            'SELECT phase FROM ' . $this->migrations_table() . ' WHERE phase = ?',
            [$phase]
        );
        return $row !== null;
    }

    private function ensure_migrations_table(): void {
        $table = $this->migrations_table();
        if ($this->db->table_exists($table)) {
            return;
        }
        if ($this->db->dialect() === 'sqlite') {
            $this->db->exec(
                'CREATE TABLE ' . $table . ' (
                    id integer PRIMARY KEY AUTOINCREMENT,
                    phase text NOT NULL UNIQUE,
                    applied_at text NOT NULL
                )'
            );
            return;
        }
        $charset = $this->db->charset_collate();
        $this->db->exec(
            'CREATE TABLE ' . $table . ' (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                phase varchar(80) NOT NULL,
                applied_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY phase (phase)
            ) ' . $charset
        );
    }

    private function migrations_table(): string {
        return $this->db->table('migrations');
    }
}
