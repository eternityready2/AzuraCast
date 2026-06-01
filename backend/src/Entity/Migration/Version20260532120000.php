<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260532120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_emergency flag on station schedules (PR13).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_schedules
             ADD COLUMN IF NOT EXISTS is_emergency TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_schedules
             DROP COLUMN IF EXISTS is_emergency'
        );
    }
}
