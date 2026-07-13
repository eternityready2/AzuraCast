<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v0.32: playlist rotation_goal_days for positive rotation goals (C1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists ADD COLUMN IF NOT EXISTS rotation_goal_days INT DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_playlists DROP COLUMN IF EXISTS rotation_goal_days'
        );
    }
}
