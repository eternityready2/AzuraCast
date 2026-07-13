<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use App\Attributes\StableMigration;
use Doctrine\DBAL\Schema\Schema;

#[StableMigration('0.29.0')]
final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Station-wide top-of-hour ID protection queue flags (v0.29).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             ADD COLUMN IF NOT EXISTS top_of_hour_legal_id TINYINT(1) NOT NULL DEFAULT 0,
             ADD COLUMN IF NOT EXISTS hour_boundary_enforce_cap TINYINT(1) NOT NULL DEFAULT 0,
             ADD COLUMN IF NOT EXISTS hour_boundary_max_play_seconds INT DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             DROP COLUMN IF EXISTS top_of_hour_legal_id,
             DROP COLUMN IF EXISTS hour_boundary_enforce_cap,
             DROP COLUMN IF EXISTS hour_boundary_max_play_seconds'
        );
    }
}
