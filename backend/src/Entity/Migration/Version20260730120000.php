<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_playlist_group table for the Playlist Groups (clock wheel grouping) feature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS station_playlist_group (
                    id INT AUTO_INCREMENT NOT NULL,
                    playlist_id INT NOT NULL,
                    playlist_group_id INT NOT NULL,
                    weight INT NOT NULL,
                    is_queued TINYINT(1) NOT NULL,
                    last_played INT NOT NULL,
                    consecutive_plays INT NOT NULL,
                    consecutive_plays_count INT NOT NULL,
                    play_full_cycle TINYINT(1) NOT NULL,
                    allowed_requests VARCHAR(255) NOT NULL,
                    INDEX IDX_station_playlist_group_playlist_id (playlist_id),
                    INDEX IDX_station_playlist_group_playlist_group_id (playlist_group_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_group
                ADD CONSTRAINT FK_station_playlist_group_playlist_id
                FOREIGN KEY IF NOT EXISTS (playlist_id)
                REFERENCES station_playlists (id)
                ON DELETE CASCADE
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_group
                ADD CONSTRAINT FK_station_playlist_group_playlist_group_id
                FOREIGN KEY IF NOT EXISTS (playlist_group_id)
                REFERENCES station_playlists (id)
                ON DELETE CASCADE
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_group
                DROP FOREIGN KEY IF EXISTS FK_station_playlist_group_playlist_id
                SQL
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_group
                DROP FOREIGN KEY IF EXISTS FK_station_playlist_group_playlist_group_id
                SQL
        );
        $this->addSql('DROP TABLE IF EXISTS station_playlist_group');
    }
}
