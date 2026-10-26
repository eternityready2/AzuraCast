<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260725130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add voice_speed and use_background_audio columns to ai_dj (same class of gap as talk_frequency).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE ai_dj
             ADD COLUMN IF NOT EXISTS voice_speed DOUBLE PRECISION NOT NULL DEFAULT 1.0'
        );
        $this->addSql(
            'ALTER TABLE ai_dj
             ADD COLUMN IF NOT EXISTS use_background_audio TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE ai_dj
             DROP COLUMN IF EXISTS voice_speed'
        );
        $this->addSql(
            'ALTER TABLE ai_dj
             DROP COLUMN IF EXISTS use_background_audio'
        );
    }
}
