<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260725120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add talk_frequency column to ai_dj table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE ai_dj
             ADD COLUMN IF NOT EXISTS talk_frequency DOUBLE PRECISION NOT NULL DEFAULT 0.5'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE ai_dj
             DROP COLUMN IF EXISTS talk_frequency'
        );
    }
}
