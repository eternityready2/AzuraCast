<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260714000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clock_wheel_stretch_ratio to station_queue for time-stretch/squeeze support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE station_queue
                ADD COLUMN IF NOT EXISTS clock_wheel_stretch_ratio DOUBLE PRECISION DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_queue DROP COLUMN IF EXISTS clock_wheel_stretch_ratio');
    }
}
