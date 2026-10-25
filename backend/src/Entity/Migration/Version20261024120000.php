<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Advertisers and ad orders: selling airtime, and the spots that deliver it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ad_advertisers (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                name VARCHAR(190) NOT NULL,
                contact_name VARCHAR(190) DEFAULT NULL,
                email VARCHAR(190) DEFAULT NULL,
                phone VARCHAR(60) DEFAULT NULL,
                website VARCHAR(255) DEFAULT NULL,
                is_nonprofit TINYINT(1) NOT NULL DEFAULT 0,
                notes LONGTEXT DEFAULT NULL,
                created_at INT NOT NULL,
                INDEX IDX_AD_ADVERTISER_STATION (station_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE ad_advertisers
                ADD CONSTRAINT FK_AD_ADVERTISER_STATION FOREIGN KEY (station_id)
                REFERENCES station (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'CREATE TABLE ad_orders (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                advertiser_id INT NOT NULL,
                playlist_id INT DEFAULT NULL,
                media_id INT DEFAULT NULL,
                name VARCHAR(190) NOT NULL,
                product VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL,
                spots_per_day SMALLINT NOT NULL DEFAULT 0,
                spot_seconds SMALLINT NOT NULL DEFAULT 30,
                is_live_read TINYINT(1) NOT NULL DEFAULT 0,
                script LONGTEXT DEFAULT NULL,
                program_name VARCHAR(190) DEFAULT NULL,
                starts_at INT DEFAULT NULL,
                ends_at INT DEFAULT NULL,
                rate_monthly NUMERIC(10, 2) DEFAULT NULL,
                po_number VARCHAR(80) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at INT NOT NULL,
                INDEX IDX_AD_ORDER_STATION (station_id),
                INDEX IDX_AD_ORDER_ADVERTISER (advertiser_id),
                INDEX idx_ad_orders_status_dates (station_id, status, starts_at, ends_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE ad_orders
                ADD CONSTRAINT FK_AD_ORDER_STATION FOREIGN KEY (station_id)
                    REFERENCES station (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_AD_ORDER_ADVERTISER FOREIGN KEY (advertiser_id)
                    REFERENCES ad_advertisers (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_AD_ORDER_PLAYLIST FOREIGN KEY (playlist_id)
                    REFERENCES station_playlists (id) ON DELETE SET NULL,
                ADD CONSTRAINT FK_AD_ORDER_MEDIA FOREIGN KEY (media_id)
                    REFERENCES station_media (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ad_orders');
        $this->addSql('DROP TABLE ad_advertisers');
    }
}
