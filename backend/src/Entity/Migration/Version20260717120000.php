<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add library aging threshold (playlists) and per-song time restrictions (media).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists
                ADD COLUMN IF NOT EXISTS aging_threshold_days INT DEFAULT NULL'
        );

        $this->addSql(
            "ALTER TABLE station_media
                ADD COLUMN IF NOT EXISTS allowed_days LONGTEXT NOT NULL DEFAULT '[]',
                ADD COLUMN IF NOT EXISTS allowed_start_minute INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS allowed_end_minute INT DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_playlists DROP COLUMN IF EXISTS aging_threshold_days');
        $this->addSql(
            'ALTER TABLE station_media
                DROP COLUMN IF EXISTS allowed_days,
                DROP COLUMN IF EXISTS allowed_start_minute,
                DROP COLUMN IF EXISTS allowed_end_minute'
        );
    }
}
