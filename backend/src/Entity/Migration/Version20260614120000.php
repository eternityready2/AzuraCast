<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v0.34: clock wheel slot hard anchors, research scores, and sound codes (Phase D).';
    }

    public function up(Schema $schema): void
    {
        foreach (['station_clock_wheel_slots', 'station_clock_wheel_template_slots'] as $table) {
            $this->addSql(
                "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS is_hard_anchor TINYINT(1) NOT NULL DEFAULT 0"
            );
            $this->addSql(
                "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS research_score SMALLINT UNSIGNED DEFAULT NULL"
            );
            $this->addSql(
                "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS sound_code VARCHAR(20) DEFAULT NULL"
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['station_clock_wheel_slots', 'station_clock_wheel_template_slots'] as $table) {
            $this->addSql("ALTER TABLE {$table} DROP COLUMN IF EXISTS is_hard_anchor");
            $this->addSql("ALTER TABLE {$table} DROP COLUMN IF EXISTS research_score");
            $this->addSql("ALTER TABLE {$table} DROP COLUMN IF EXISTS sound_code");
        }
    }
}
