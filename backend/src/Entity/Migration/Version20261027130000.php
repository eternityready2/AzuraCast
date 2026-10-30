<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Smart blocks are their own records (Version20261027120000 moved them); the
 * playlist columns and the old rules table that carried them go.
 */
final class Version20261027130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the smart block columns from playlists and the old smart block rules table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS station_playlist_smart_block_criteria');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                DROP COLUMN is_smart_block,
                DROP COLUMN smart_block_match_type,
                DROP COLUMN smart_block_limit,
                DROP COLUMN smart_block_limit_type,
                DROP COLUMN smart_block_type,
                DROP COLUMN smart_block_sort_order,
                DROP COLUMN smart_block_avoid_duplicates
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlists
                ADD is_smart_block TINYINT(1) DEFAULT 0 NOT NULL,
                ADD smart_block_match_type VARCHAR(10) DEFAULT 'all' NOT NULL,
                ADD smart_block_limit INT DEFAULT NULL,
                ADD smart_block_limit_type VARCHAR(10) DEFAULT 'tracks' NOT NULL,
                ADD smart_block_type VARCHAR(10) DEFAULT 'dynamic' NOT NULL,
                ADD smart_block_sort_order VARCHAR(20) DEFAULT 'random' NOT NULL,
                ADD smart_block_avoid_duplicates TINYINT(1) DEFAULT 1 NOT NULL
            SQL
        );
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS station_playlist_smart_block_criteria (
                    id INT AUTO_INCREMENT NOT NULL,
                    playlist_id INT NOT NULL,
                    custom_field_id INT DEFAULT NULL,
                    field VARCHAR(25) NOT NULL,
                    comparison VARCHAR(25) NOT NULL,
                    value VARCHAR(255) DEFAULT NULL,
                    value2 VARCHAR(255) DEFAULT NULL,
                    weight INT DEFAULT 0 NOT NULL,
                    INDEX IDX_station_playlist_smart_block_criteria_playlist_id (playlist_id),
                    INDEX IDX_station_playlist_smart_block_criteria_custom_field_id (custom_field_id),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB
            SQL
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                ADD CONSTRAINT FK_station_playlist_smart_block_criteria_playlist_id
                FOREIGN KEY (playlist_id) REFERENCES station_playlists (id) ON DELETE CASCADE
            SQL
        );
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_playlist_smart_block_criteria
                ADD CONSTRAINT FK_station_playlist_smart_block_criteria_custom_field_id
                FOREIGN KEY (custom_field_id) REFERENCES custom_field (id) ON DELETE SET NULL
            SQL
        );
    }
}
