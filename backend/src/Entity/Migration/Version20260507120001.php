<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260507120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align station_clock_wheels and station_clock_wheel_slots schema with current entity definitions.';
    }

    public function up(Schema $schema): void
    {
        // station_clock_wheels:
        //   Remove: description (not in entity), strict_timing (replaced by is_active)
        //   Add:    color (#rrggbb swatch)
        //   Alter:  name from varchar(200) -> varchar(100)
        $this->addSql("ALTER TABLE station_clock_wheels
            DROP COLUMN description,
            DROP COLUMN strict_timing,
            ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#e87722' AFTER name,
            MODIFY COLUMN name VARCHAR(100) NOT NULL
        ");

        // station_clock_wheel_slots:
        //   Remove: label, position_seconds, weight (not in entity)
        //   Rename: slot_type  -> type
        //           sort_order -> slot_order
        //   Add:    algorithm (selection strategy enum)
        //   Alter:  duration_seconds to allow NULL (NULL = no hard cap / play full track)
        $this->addSql("ALTER TABLE station_clock_wheel_slots
            DROP COLUMN label,
            DROP COLUMN position_seconds,
            DROP COLUMN weight,
            CHANGE COLUMN slot_type  `type`       VARCHAR(20) NOT NULL DEFAULT 'music',
            CHANGE COLUMN sort_order  slot_order  SMALLINT NOT NULL DEFAULT 0,
            ADD COLUMN algorithm VARCHAR(30) NOT NULL DEFAULT 'random' AFTER `type`,
            MODIFY COLUMN duration_seconds SMALLINT NULL DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Restore station_clock_wheels to previous shape
        $this->addSql("ALTER TABLE station_clock_wheels
            DROP COLUMN color,
            ADD COLUMN description LONGTEXT NULL,
            ADD COLUMN strict_timing TINYINT(1) NOT NULL DEFAULT 1,
            MODIFY COLUMN name VARCHAR(200) NOT NULL
        ");

        // Restore station_clock_wheel_slots to previous shape
        $this->addSql("ALTER TABLE station_clock_wheel_slots
            DROP COLUMN algorithm,
            ADD COLUMN label VARCHAR(150) NULL,
            ADD COLUMN position_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            CHANGE COLUMN `type`      slot_type  VARCHAR(30) NOT NULL DEFAULT 'music',
            CHANGE COLUMN slot_order  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            MODIFY COLUMN duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 210
        ");
    }
}
