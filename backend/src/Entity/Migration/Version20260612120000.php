<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v0.33: playlist crossfade_profile for content-type crossfade overrides (§7).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists ADD COLUMN IF NOT EXISTS crossfade_profile VARCHAR(50) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists DROP COLUMN IF EXISTS crossfade_profile'
        );
    }
}
