<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add playlist_chain tracking columns to station_queue and song_history '
            . 'for the Playlist Groups feature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS playlist_chain LONGTEXT DEFAULT NULL
                COMMENT '(DC2Type:json)'
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE song_history
                ADD COLUMN IF NOT EXISTS playlist_chain LONGTEXT DEFAULT NULL
                COMMENT '(DC2Type:json)'
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE station_queue
                DROP COLUMN IF EXISTS playlist_chain
                SQL
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE song_history
                DROP COLUMN IF EXISTS playlist_chain
                SQL
        );
    }
}
