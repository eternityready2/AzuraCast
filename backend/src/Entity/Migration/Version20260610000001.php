<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260610000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shift_outro_template column to ai_dj table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj ADD shift_outro_template LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj DROP shift_outro_template');
    }
}
