<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legal ID clock wheel: queue substitute flag and event queue link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
             ADD COLUMN IF NOT EXISTS clock_wheel_legal_id_substitute TINYINT(1) NOT NULL DEFAULT 0'
        );

        $this->addSql(
            'ALTER TABLE clock_wheel_events
             ADD COLUMN IF NOT EXISTS station_queue_id INT DEFAULT NULL,
             ADD CONSTRAINT fk_cwe_station_queue
                 FOREIGN KEY IF NOT EXISTS (station_queue_id)
                 REFERENCES station_queue (id) ON DELETE SET NULL'
        );

        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_cwe_legal_id_playback
             ON clock_wheel_events (clock_wheel_id, anchor_type, actual_play_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clock_wheel_events DROP FOREIGN KEY IF EXISTS fk_cwe_station_queue');
        $this->addSql('ALTER TABLE clock_wheel_events DROP COLUMN IF EXISTS station_queue_id');
        $this->addSql('ALTER TABLE station_queue DROP COLUMN IF EXISTS clock_wheel_legal_id_substitute');
    }
}
