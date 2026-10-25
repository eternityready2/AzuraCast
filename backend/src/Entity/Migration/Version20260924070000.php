<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260924070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saved 24-hour linear log lines and their queue-row link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE station_log_entries (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                media_id INT DEFAULT NULL,
                playlist_id INT DEFAULT NULL,
                planned_at INT NOT NULL,
                sequence INT NOT NULL,
                duration DOUBLE PRECISION NOT NULL,
                status VARCHAR(20) NOT NULL,
                text VARCHAR(255) DEFAULT NULL,
                title VARCHAR(255) DEFAULT NULL,
                artist VARCHAR(255) DEFAULT NULL,
                payload JSON DEFAULT NULL,
                queue_id INT DEFAULT NULL,
                aired_at INT DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                is_locked TINYINT(1) NOT NULL,
                created_at INT NOT NULL,
                INDEX idx_station_log_planned (station_id, planned_at),
                INDEX idx_station_log_status (station_id, status),
                INDEX IDX_LOG_MEDIA (media_id),
                INDEX IDX_LOG_PLAYLIST (playlist_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE station_log_entries
                ADD CONSTRAINT FK_LOG_STATION FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_LOG_MEDIA FOREIGN KEY (media_id) REFERENCES station_media (id) ON DELETE SET NULL,
                ADD CONSTRAINT FK_LOG_PLAYLIST FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE SET NULL'
        );
        $this->addSql('ALTER TABLE station_queue ADD log_entry_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_queue DROP log_entry_id');
        $this->addSql('DROP TABLE station_log_entries');
    }
}
