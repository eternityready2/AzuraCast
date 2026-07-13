<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v0.30: clock wheel slot pool mode, per-slot separation overrides, smart shuffle playlist order.';
    }

    public function up(Schema $schema): void
    {
        foreach (['station_clock_wheel_slots', 'station_clock_wheel_template_slots'] as $table) {
            $this->addSql(
                "ALTER TABLE {$table}
                 ADD COLUMN IF NOT EXISTS pool_mode VARCHAR(30) NOT NULL DEFAULT 'restrict_pool',
                 ADD COLUMN IF NOT EXISTS separation_override_enabled TINYINT(1) NOT NULL DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS separation_artist_minutes INT DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS separation_title_minutes INT DEFAULT NULL"
            );
        }

        $this->addSql(
            "ALTER TABLE station_playlists
             ADD COLUMN IF NOT EXISTS smart_shuffle_distance INT DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        foreach (['station_clock_wheel_slots', 'station_clock_wheel_template_slots'] as $table) {
            $this->addSql(
                "ALTER TABLE {$table}
                 DROP COLUMN IF EXISTS pool_mode,
                 DROP COLUMN IF EXISTS separation_override_enabled,
                 DROP COLUMN IF EXISTS separation_artist_minutes,
                 DROP COLUMN IF EXISTS separation_title_minutes"
            );
        }

        $this->addSql(
            'ALTER TABLE station_playlists DROP COLUMN IF EXISTS smart_shuffle_distance'
        );
    }
}
