<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A smart block plays in its calendar time; a weight (how often against other
 * playlists in rotation) has nothing to act on, so the column goes.
 */
final class Version20261027150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the unused weight column from smart blocks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_smart_blocks DROP COLUMN weight');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_smart_blocks ADD weight INT DEFAULT 3 NOT NULL');
    }
}
