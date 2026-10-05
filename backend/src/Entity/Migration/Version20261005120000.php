<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Days of the week a clock daypart airs on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_clock_dayparts ADD days VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_clock_dayparts DROP days');
    }
}
