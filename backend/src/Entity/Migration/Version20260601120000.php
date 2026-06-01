<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

/**
 * PR10 — daypart clock inheritance (templates, dayparts, wheel links).
 */
final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clock wheel templates, template slots, dayparts, and wheel inheritance columns (PR10).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS station_clock_wheel_templates (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT \'#e87722\',
                INDEX IDX_clock_wheel_template_station (station_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_clock_wheel_template_station
                    FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS station_clock_wheel_template_slots (
                id INT AUTO_INCREMENT NOT NULL,
                template_id INT NOT NULL,
                playlist_id INT DEFAULT NULL,
                category_id INT DEFAULT NULL,
                type VARCHAR(20) DEFAULT NULL,
                algorithm VARCHAR(30) NOT NULL,
                position_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                slot_order SMALLINT NOT NULL DEFAULT 0,
                duration_seconds SMALLINT DEFAULT NULL,
                INDEX IDX_clock_wheel_template_slot_template (template_id),
                INDEX IDX_clock_wheel_template_slot_playlist (playlist_id),
                INDEX IDX_clock_wheel_template_slot_category (category_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_clock_wheel_template_slot_template
                    FOREIGN KEY (template_id) REFERENCES station_clock_wheel_templates (id) ON DELETE CASCADE,
                CONSTRAINT FK_clock_wheel_template_slot_playlist
                    FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE SET NULL,
                CONSTRAINT FK_clock_wheel_template_slot_category
                    FOREIGN KEY (category_id) REFERENCES station_media_categories (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS station_clock_dayparts (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                template_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                start_hour TINYINT UNSIGNED NOT NULL,
                end_hour TINYINT UNSIGNED NOT NULL,
                color VARCHAR(7) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX IDX_clock_daypart_station (station_id),
                INDEX IDX_clock_daypart_template (template_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_clock_daypart_station
                    FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE,
                CONSTRAINT FK_clock_daypart_template
                    FOREIGN KEY (template_id) REFERENCES station_clock_wheel_templates (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE station_clock_wheels
             ADD COLUMN IF NOT EXISTS template_id INT DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS daypart_id INT DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS hour_of_day TINYINT UNSIGNED DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS inherits_template_slots TINYINT(1) NOT NULL DEFAULT 0'
        );

        $this->addSql(
            'ALTER TABLE station_clock_wheels
             ADD INDEX IF NOT EXISTS IDX_clock_wheel_template (template_id),
             ADD INDEX IF NOT EXISTS IDX_clock_wheel_daypart (daypart_id),
             ADD UNIQUE INDEX IF NOT EXISTS UNIQ_clock_wheel_daypart_hour (daypart_id, hour_of_day)'
        );

        $this->addSql(
            'ALTER TABLE station_clock_wheels
             ADD CONSTRAINT FK_clock_wheel_template
                 FOREIGN KEY (template_id) REFERENCES station_clock_wheel_templates (id) ON DELETE SET NULL'
        );

        $this->addSql(
            'ALTER TABLE station_clock_wheels
             ADD CONSTRAINT FK_clock_wheel_daypart
                 FOREIGN KEY (daypart_id) REFERENCES station_clock_dayparts (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_clock_wheels DROP FOREIGN KEY IF EXISTS FK_clock_wheel_daypart');
        $this->addSql('ALTER TABLE station_clock_wheels DROP FOREIGN KEY IF EXISTS FK_clock_wheel_template');
        $this->addSql(
            'ALTER TABLE station_clock_wheels
             DROP INDEX IF EXISTS UNIQ_clock_wheel_daypart_hour,
             DROP INDEX IF EXISTS IDX_clock_wheel_daypart,
             DROP INDEX IF EXISTS IDX_clock_wheel_template,
             DROP COLUMN IF EXISTS inherits_template_slots,
             DROP COLUMN IF EXISTS hour_of_day,
             DROP COLUMN IF EXISTS daypart_id,
             DROP COLUMN IF EXISTS template_id'
        );
        $this->addSql('DROP TABLE IF EXISTS station_clock_dayparts');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_template_slots');
        $this->addSql('DROP TABLE IF EXISTS station_clock_wheel_templates');
    }
}
