<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260531120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-wheel separation and burn-rate settings (PR9).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheels
             ADD COLUMN IF NOT EXISTS separation_enabled TINYINT(1) NOT NULL DEFAULT 0,
             ADD COLUMN IF NOT EXISTS separation_artist_minutes SMALLINT UNSIGNED DEFAULT 45,
             ADD COLUMN IF NOT EXISTS separation_title_minutes SMALLINT UNSIGNED DEFAULT 90,
             ADD COLUMN IF NOT EXISTS burn_rate_max_plays_24h SMALLINT UNSIGNED DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheels
             DROP COLUMN IF EXISTS separation_enabled,
             DROP COLUMN IF EXISTS separation_artist_minutes,
             DROP COLUMN IF EXISTS separation_title_minutes,
             DROP COLUMN IF EXISTS burn_rate_max_plays_24h'
        );
    }
}
