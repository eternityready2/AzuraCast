<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

/**
 * Smart Blocks become their own feature instead of playlists.
 *
 * Each smart block playlist (station_playlists.is_smart_block = 1) is copied into
 * station_smart_blocks with its rules; its song memory, schedules, clock wheel slots,
 * queued rows, play history and log lines are pointed at the new smart block; then
 * the playlist is removed.
 *
 * A smart block playlist that something else still needs as a playlist (a playlist
 * group member, a holiday override, an ad order, a podcast) is kept as an ordinary
 * playlist with the songs it has now, and the smart block is created alongside it,
 * so nothing a station relies on disappears.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Smart Blocks: their own tables; existing smart block playlists move over.';
    }

    public function up(Schema $schema): void
    {
        // --- New tables ---------------------------------------------------------
        $this->addSql(
            "CREATE TABLE station_smart_blocks (station_id INT NOT NULL, name VARCHAR(200) NOT NULL, is_enabled TINYINT DEFAULT 1 NOT NULL, weight INT DEFAULT 3 NOT NULL, type VARCHAR(10) DEFAULT 'dynamic' NOT NULL, match_type VARCHAR(10) DEFAULT 'all' NOT NULL, limit_value INT DEFAULT NULL, limit_type VARCHAR(10) DEFAULT 'tracks' NOT NULL, sort_order VARCHAR(20) DEFAULT 'random' NOT NULL, avoid_duplicates TINYINT DEFAULT 1 NOT NULL, id INT AUTO_INCREMENT NOT NULL, legacy_playlist_id INT DEFAULT NULL, INDEX IDX_D041B5A521BDB235 (station_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB"
        );
        $this->addSql(
            'CREATE TABLE station_smart_block_criteria (field VARCHAR(25) NOT NULL, comparison VARCHAR(25) NOT NULL, value VARCHAR(255) DEFAULT NULL, value2 VARCHAR(255) DEFAULT NULL, weight INT NOT NULL, id INT AUTO_INCREMENT NOT NULL, smart_block_id INT NOT NULL, custom_field_id INT DEFAULT NULL, INDEX IDX_FCBC549C555680C (smart_block_id), INDEX IDX_FCBC549A1E5E0D4 (custom_field_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE station_smart_block_media (is_member TINYINT DEFAULT 0 NOT NULL, weight INT DEFAULT 0 NOT NULL, last_played INT DEFAULT 0 NOT NULL, id INT AUTO_INCREMENT NOT NULL, smart_block_id INT NOT NULL, media_id INT NOT NULL, INDEX IDX_7849E8E7C555680C (smart_block_id), INDEX IDX_7849E8E7EA9FDD75 (media_id), UNIQUE INDEX smart_block_media_unique (smart_block_id, media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE station_smart_blocks ADD CONSTRAINT FK_D041B5A521BDB235 FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE station_smart_block_criteria ADD CONSTRAINT FK_FCBC549C555680C FOREIGN KEY (smart_block_id) REFERENCES station_smart_blocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE station_smart_block_criteria ADD CONSTRAINT FK_FCBC549A1E5E0D4 FOREIGN KEY (custom_field_id) REFERENCES custom_field (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE station_smart_block_media ADD CONSTRAINT FK_7849E8E7C555680C FOREIGN KEY (smart_block_id) REFERENCES station_smart_blocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE station_smart_block_media ADD CONSTRAINT FK_7849E8E7EA9FDD75 FOREIGN KEY (media_id) REFERENCES station_media (id) ON DELETE CASCADE');

        // --- Links from existing tables ----------------------------------------
        $links = [
            'station_schedules' => ['FK_B3BFB295C555680C', 'IDX_B3BFB295C555680C', 'CASCADE'],
            'station_queue' => ['FK_277B0055C555680C', 'IDX_277B0055C555680C', 'SET NULL'],
            'song_history' => ['FK_2AD16164C555680C', 'IDX_2AD16164C555680C', 'SET NULL'],
            'station_log_entries' => ['FK_16098E13C555680C', 'IDX_16098E13C555680C', 'SET NULL'],
            'station_clock_wheel_slots' => ['FK_A63DB733C555680C', 'IDX_A63DB733C555680C', 'SET NULL'],
            'station_clock_wheel_template_slots' => ['FK_A6A8716C555680C', 'IDX_A6A8716C555680C', 'SET NULL'],
        ];
        foreach ($links as $table => [$fk, $idx, $onDelete]) {
            $this->addSql(sprintf('ALTER TABLE %s ADD smart_block_id INT DEFAULT NULL', $table));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (smart_block_id) REFERENCES station_smart_blocks (id) ON DELETE %s',
                $table,
                $fk,
                $onDelete
            ));
            $this->addSql(sprintf('CREATE INDEX %s ON %s (smart_block_id)', $idx, $table));
        }

        // --- Move existing smart block playlists --------------------------------
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_smart_blocks
                    (station_id, name, is_enabled, weight, type, match_type, limit_value, limit_type,
                     sort_order, avoid_duplicates, legacy_playlist_id)
                SELECT station_id, name, is_enabled, weight, smart_block_type, smart_block_match_type,
                       smart_block_limit, smart_block_limit_type, smart_block_sort_order,
                       smart_block_avoid_duplicates, id
                FROM station_playlists
                WHERE is_smart_block = 1
            SQL
        );
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_smart_block_criteria
                    (smart_block_id, field, custom_field_id, comparison, value, value2, weight)
                SELECT sb.id, c.field, c.custom_field_id, c.comparison, c.value, c.value2, c.weight
                FROM station_playlist_smart_block_criteria c
                JOIN station_smart_blocks sb ON sb.legacy_playlist_id = c.playlist_id
            SQL
        );
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_smart_block_media (smart_block_id, media_id, is_member, weight, last_played)
                SELECT sb.id, spm.media_id, IF(sb.type = 'static', 1, 0), MIN(spm.weight), MAX(spm.last_played)
                FROM station_playlist_media spm
                JOIN station_smart_blocks sb ON sb.legacy_playlist_id = spm.playlist_id
                GROUP BY sb.id, spm.media_id
            SQL
        );

        foreach (
            [
                'station_schedules',
                'station_clock_wheel_slots',
                'station_clock_wheel_template_slots',
                'station_queue',
                'song_history',
                'station_log_entries',
            ] as $table
        ) {
            $this->addSql(sprintf(
                'UPDATE %s t JOIN station_smart_blocks sb ON sb.legacy_playlist_id = t.playlist_id
                 SET t.smart_block_id = sb.id, t.playlist_id = NULL',
                $table
            ));
        }

        // Kept as an ordinary playlist where something still needs a playlist.
        $this->addSql(
            <<<'SQL'
                UPDATE station_playlists p
                SET p.is_smart_block = 0
                WHERE p.is_smart_block = 1 AND (
                    EXISTS (SELECT 1 FROM station_playlist_group_members g WHERE g.playlist_id = p.id)
                    OR EXISTS (SELECT 1 FROM station_holiday_overrides h WHERE h.playlist_id = p.id)
                    OR EXISTS (SELECT 1 FROM ad_orders a WHERE a.playlist_id = p.id)
                    OR EXISTS (SELECT 1 FROM podcast c WHERE c.playlist_id = p.id)
                )
            SQL
        );
        $this->addSql('DELETE FROM station_playlists WHERE is_smart_block = 1');
        $this->addSql('ALTER TABLE station_smart_blocks DROP legacy_playlist_id');
    }

    public function down(Schema $schema): void
    {
        // Smart blocks become smart block playlists again (with their rules,
        // schedules and clock wheel slots), then the new tables go.
        // Matched by id through a temporary column, never by name.
        $this->addSql('ALTER TABLE station_smart_blocks ADD legacy_playlist_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE station_playlists ADD legacy_smart_block_id INT DEFAULT NULL');
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_playlists
                    (station_id, name, type, source, playback_order, remote_timeout, is_enabled, is_jingle,
                     play_per_songs, play_per_minutes, play_per_hour_minute, weight, include_in_requests,
                     include_in_on_demand, avoid_duplicates, preserve_queue_on_restart, is_smart_block, smart_block_type,
                     smart_block_match_type, smart_block_limit, smart_block_limit_type,
                     smart_block_sort_order, smart_block_avoid_duplicates, legacy_smart_block_id)
                SELECT station_id, name, 'default', 'songs', 'shuffle', 0, is_enabled, 0,
                       0, 0, 0, weight, 1,
                       0, avoid_duplicates, 0, 1, type,
                       match_type, limit_value, limit_type,
                       sort_order, avoid_duplicates, id
                FROM station_smart_blocks
            SQL
        );
        $this->addSql(
            <<<'SQL'
                UPDATE station_smart_blocks sb
                JOIN station_playlists p ON p.legacy_smart_block_id = sb.id
                SET sb.legacy_playlist_id = p.id
            SQL
        );
        $this->addSql('ALTER TABLE station_playlists DROP legacy_smart_block_id');
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_playlist_smart_block_criteria
                    (playlist_id, field, custom_field_id, comparison, value, value2, weight)
                SELECT sb.legacy_playlist_id, c.field, c.custom_field_id, c.comparison, c.value, c.value2, c.weight
                FROM station_smart_block_criteria c
                JOIN station_smart_blocks sb ON sb.id = c.smart_block_id
                WHERE sb.legacy_playlist_id IS NOT NULL
            SQL
        );
        $this->addSql(
            <<<'SQL'
                INSERT INTO station_playlist_media (playlist_id, media_id, weight, last_played, is_queued)
                SELECT sb.legacy_playlist_id, m.media_id, m.weight, m.last_played, 0
                FROM station_smart_block_media m
                JOIN station_smart_blocks sb ON sb.id = m.smart_block_id
                WHERE sb.legacy_playlist_id IS NOT NULL
            SQL
        );
        foreach (['station_schedules', 'station_clock_wheel_slots', 'station_clock_wheel_template_slots'] as $table) {
            $this->addSql(sprintf(
                'UPDATE %s t JOIN station_smart_blocks sb ON sb.id = t.smart_block_id
                 SET t.playlist_id = sb.legacy_playlist_id',
                $table
            ));
        }

        foreach (
            [
                'station_schedules' => 'FK_B3BFB295C555680C',
                'station_queue' => 'FK_277B0055C555680C',
                'song_history' => 'FK_2AD16164C555680C',
                'station_log_entries' => 'FK_16098E13C555680C',
                'station_clock_wheel_slots' => 'FK_A63DB733C555680C',
                'station_clock_wheel_template_slots' => 'FK_A6A8716C555680C',
            ] as $table => $fk
        ) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $fk));
            $this->addSql(sprintf('ALTER TABLE %s DROP smart_block_id', $table));
        }

        $this->addSql('DROP TABLE station_smart_block_media');
        $this->addSql('DROP TABLE station_smart_block_criteria');
        $this->addSql('DROP TABLE station_smart_blocks');
    }
}
