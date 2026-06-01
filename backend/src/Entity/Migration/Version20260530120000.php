<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260530120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fill_strategy to station_clock_wheels for preview/planner tuning (PR12).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_clock_wheels
             ADD COLUMN IF NOT EXISTS fill_strategy VARCHAR(20) NOT NULL DEFAULT 'conservative'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_clock_wheels DROP COLUMN IF EXISTS fill_strategy');
    }
}
