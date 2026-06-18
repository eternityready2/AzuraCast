<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v0.35: media do-not-play restrictions and station holiday overrides (Phase E).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS do_not_play TINYINT(1) NOT NULL DEFAULT 0'
        );
        $this->addSql(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS do_not_play_reason VARCHAR(255) DEFAULT NULL'
        );
        $this->addSql(
            'ALTER TABLE station_media ADD COLUMN IF NOT EXISTS do_not_play_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''
        );

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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS station_holiday_overrides');
        $this->addSql('ALTER TABLE station_media DROP COLUMN IF EXISTS do_not_play_until');
        $this->addSql('ALTER TABLE station_media DROP COLUMN IF EXISTS do_not_play_reason');
        $this->addSql('ALTER TABLE station_media DROP COLUMN IF EXISTS do_not_play');
    }
}
