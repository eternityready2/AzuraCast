<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sponsor guaranteed playout fields to station_playlists.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists
                ADD COLUMN IF NOT EXISTS is_sponsor TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS sponsor_name VARCHAR(255) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS sponsor_guaranteed_plays_per_day INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS sponsor_contract_start DATETIME(6) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS sponsor_contract_end DATETIME(6) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists
                DROP COLUMN IF EXISTS is_sponsor,
                DROP COLUMN IF EXISTS sponsor_name,
                DROP COLUMN IF EXISTS sponsor_guaranteed_plays_per_day,
                DROP COLUMN IF EXISTS sponsor_contract_start,
                DROP COLUMN IF EXISTS sponsor_contract_end'
        );
    }
}
