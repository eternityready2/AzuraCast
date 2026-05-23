<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position_seconds to station_clock_wheel_slots for hour-anchored playback.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_slots
                ADD COLUMN IF NOT EXISTS position_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0'
        );

        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_scws_wheel_position
             ON station_clock_wheel_slots (clock_wheel_id, position_seconds)'
        );

        $this->addSql(
            'UPDATE station_clock_wheel_slots
             SET position_seconds = LEAST(slot_order * 300, 3599)
             WHERE position_seconds = 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_scws_wheel_position ON station_clock_wheel_slots');
        $this->addSql('ALTER TABLE station_clock_wheel_slots DROP COLUMN IF EXISTS position_seconds');
    }
}
