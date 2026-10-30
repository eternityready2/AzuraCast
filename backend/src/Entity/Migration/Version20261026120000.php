<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Playlists: which schedule editor (Classic or New) each playlist uses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_playlists ADD schedule_editor VARCHAR(10) DEFAULT 'classic' NOT NULL"
        );

        // A playlist already using something only the New Schedule shows starts
        // on the New Schedule, where all of it is visible: Allow Overrun (not on
        // the Advanced tab), or a schedule with a repeat other than weekly,
        // every few weeks, an end rule, or a strict start.
        $this->addSql(
            <<<'SQL'
                UPDATE station_playlists p
                SET p.schedule_editor = 'new'
                WHERE FIND_IN_SET('allow_overrun', p.backend_options) > 0
                OR EXISTS (
                    SELECT 1 FROM station_schedules s
                    WHERE s.playlist_id = p.id
                    AND (
                        (s.recurrence_type IS NOT NULL AND s.recurrence_type <> 'weekly')
                        OR s.recurrence_interval <> 1
                        OR (s.recurrence_end_type IS NOT NULL AND s.recurrence_end_type <> 'never')
                        OR s.strict_start = 1
                    )
                )
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_playlists DROP schedule_editor');
    }
}
