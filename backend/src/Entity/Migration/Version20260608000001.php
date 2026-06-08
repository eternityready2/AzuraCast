<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260608000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing ai_dj_has_content join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS ai_dj_has_content (ai_dj_id INT NOT NULL, ai_dj_content_id INT NOT NULL, INDEX IDX_ai_dj_has_content_dj (ai_dj_id), INDEX IDX_ai_dj_has_content_content (ai_dj_content_id), PRIMARY KEY (ai_dj_id, ai_dj_content_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_dj_has_content ADD CONSTRAINT FK_ai_dj_has_content_dj FOREIGN KEY (ai_dj_id) REFERENCES ai_dj (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_dj_has_content ADD CONSTRAINT FK_ai_dj_has_content_content FOREIGN KEY (ai_dj_content_id) REFERENCES ai_dj_content (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj_has_content DROP FOREIGN KEY FK_ai_dj_has_content_dj');
        $this->addSql('ALTER TABLE ai_dj_has_content DROP FOREIGN KEY FK_ai_dj_has_content_content');
        $this->addSql('DROP TABLE IF EXISTS ai_dj_has_content');
    }
}
