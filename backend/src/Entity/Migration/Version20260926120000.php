<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark a playlist as spoken-word programming, exempt from the DMCA performance complement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists ADD is_programme TINYINT(1) DEFAULT 0 NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_playlists DROP is_programme');
    }
}
