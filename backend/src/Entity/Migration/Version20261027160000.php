<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261027160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Playlists: drop the Classic/New schedule editor choice; the Playlist page uses the Classic '
            . 'Schedule and the New Schedule is edited on the Schedule calendar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_playlists DROP schedule_editor');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE station_playlists ADD schedule_editor VARCHAR(10) DEFAULT 'classic' NOT NULL"
        );
    }
}
