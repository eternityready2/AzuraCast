<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261009060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "AI DJ: play the DJ's own recorded breaks.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj ADD recordings_per_hour SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj DROP recordings_per_hour');
    }
}
