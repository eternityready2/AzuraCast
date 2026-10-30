<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Holiday Overrides feature is gone: it covered single days only, and a
 * season (Christmas music for a month) is scheduled with calendar start and
 * end dates instead.
 */
final class Version20261027140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove station holiday overrides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS station_holiday_overrides');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS station_holiday_overrides (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                override_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                clock_wheel_id INT DEFAULT NULL,
                playlist_id INT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) DEFAULT NULL,
                INDEX IDX_holiday_station_date (station_id, override_date),
                UNIQUE INDEX UNIQ_holiday_station_date (station_id, override_date),
                PRIMARY KEY(id),
                CONSTRAINT FK_holiday_station FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE,
                CONSTRAINT FK_holiday_clock_wheel FOREIGN KEY (clock_wheel_id) REFERENCES station_clock_wheels (id) ON DELETE SET NULL,
                CONSTRAINT FK_holiday_playlist FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }
}
