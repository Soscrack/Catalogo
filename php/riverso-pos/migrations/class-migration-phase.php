<?php

declare(strict_types=1);

interface Riverso_POS_Migration_Phase {
    public function id(): string;

    public function up(Riverso_POS_Database $db): void;
}
