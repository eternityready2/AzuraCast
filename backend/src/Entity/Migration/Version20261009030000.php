<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261009030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI DJ music bed: the uploaded file and which breaks it plays under.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE ai_dj
                ADD background_audio_path VARCHAR(255) DEFAULT NULL,
                ADD background_audio_breaks JSON DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj DROP background_audio_path, DROP background_audio_breaks');
    }
}
