<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261009020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'History of hand edits on linear log lines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE station_log_edits (
                id INT AUTO_INCREMENT NOT NULL,
                station_id INT NOT NULL,
                entry_id INT NOT NULL,
                edit VARCHAR(20) NOT NULL,
                edited_at INT NOT NULL,
                before_state JSON NOT NULL,
                undone_at INT DEFAULT NULL,
                INDEX IDX_LOG_EDIT_STATION (station_id),
                INDEX idx_station_log_edits_entry (entry_id, id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE station_log_edits
                ADD CONSTRAINT FK_LOG_EDIT_STATION FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_LOG_EDIT_ENTRY FOREIGN KEY (entry_id) REFERENCES station_log_entries (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE station_log_edits');
    }
}
