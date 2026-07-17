<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260717000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add strict_start to station_schedule for real Strict-mode playlist scheduling.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_schedules
                ADD COLUMN IF NOT EXISTS strict_start TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_schedules DROP COLUMN IF EXISTS strict_start');
    }
}
