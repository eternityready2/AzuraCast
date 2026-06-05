<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use App\Entity\Attributes\StableMigration;
use Doctrine\DBAL\Schema\Schema;

#[StableMigration('0.28.0')]
#[StableMigration('0.28.1')]
final class Version20260603120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default separation settings on clock wheel templates (PR9).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_templates
             ADD COLUMN IF NOT EXISTS separation_enabled TINYINT(1) NOT NULL DEFAULT 0,
             ADD COLUMN IF NOT EXISTS separation_artist_minutes SMALLINT UNSIGNED DEFAULT 45,
             ADD COLUMN IF NOT EXISTS separation_title_minutes SMALLINT UNSIGNED DEFAULT 90,
             ADD COLUMN IF NOT EXISTS burn_rate_max_plays_24h SMALLINT UNSIGNED DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_clock_wheel_templates
             DROP COLUMN IF EXISTS separation_enabled,
             DROP COLUMN IF EXISTS separation_artist_minutes,
             DROP COLUMN IF EXISTS separation_title_minutes,
             DROP COLUMN IF EXISTS burn_rate_max_plays_24h'
        );
    }
}
