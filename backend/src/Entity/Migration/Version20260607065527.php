<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20260607065527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_dj (station_id INT NOT NULL, name VARCHAR(255) NOT NULL, is_enabled TINYINT DEFAULT 1 NOT NULL, voice_model_path VARCHAR(255) DEFAULT NULL, shift_intro_template LONGTEXT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX IDX_EF485E0121BDB235 (station_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ai_dj_content (station_id INT NOT NULL, type VARCHAR(50) NOT NULL, content LONGTEXT NOT NULL, reference VARCHAR(255) DEFAULT NULL, is_enabled TINYINT NOT NULL, is_global TINYINT NOT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX idx_ai_dj_content_station (station_id), INDEX idx_ai_dj_content_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ai_dj_schedules (ai_dj_id INT NOT NULL, name VARCHAR(255) NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, loop_days JSON NOT NULL, is_enabled TINYINT DEFAULT 1 NOT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX IDX_266583EF3BBC1690 (ai_dj_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_dj ADD CONSTRAINT FK_EF485E0121BDB235 FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_dj_content ADD CONSTRAINT FK_7C429BAF21BDB235 FOREIGN KEY (station_id) REFERENCES station (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_dj_schedules ADD CONSTRAINT FK_266583EF3BBC1690 FOREIGN KEY (ai_dj_id) REFERENCES ai_dj (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE clock_wheel_events CHANGE event_timestamp event_timestamp DATETIME NOT NULL, CHANGE expected_play_at expected_play_at DATETIME DEFAULT NULL, CHANGE actual_play_at actual_play_at DATETIME DEFAULT NULL, CHANGE separation_relaxed separation_relaxed TINYINT NOT NULL, CHANGE burn_rate_warning burn_rate_warning TINYINT NOT NULL');
        $this->addSql('ALTER TABLE clock_wheel_events RENAME INDEX fk_cwe_slot TO IDX_AE3FB81D59E5119C');
        $this->addSql('ALTER TABLE clock_wheel_events RENAME INDEX fk_cwe_media TO IDX_AE3FB81DEA9FDD75');
        $this->addSql('ALTER TABLE podcast DROP import_sync_before_hours, CHANGE auto_import_enabled auto_import_enabled TINYINT NOT NULL');
        $this->addSql('ALTER TABLE song_history RENAME INDEX idx_song_history_clock_wheel TO IDX_2AD161645D856AB6');
        $this->addSql('ALTER TABLE station DROP static_stream_url');
        $this->addSql('ALTER TABLE station_clock_dayparts CHANGE start_hour start_hour SMALLINT UNSIGNED NOT NULL, CHANGE end_hour end_hour SMALLINT UNSIGNED NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE separation_override_enabled separation_override_enabled TINYINT NOT NULL, CHANGE separation_enabled separation_enabled TINYINT NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT NULL, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE station_clock_dayparts RENAME INDEX idx_clock_daypart_station TO IDX_7B3D483C21BDB235');
        $this->addSql('ALTER TABLE station_clock_dayparts RENAME INDEX idx_clock_daypart_template TO IDX_7B3D483C5DA0FB8');
        $this->addSql('DROP INDEX idx_scws_wheel_position ON station_clock_wheel_slots');
        $this->addSql('ALTER TABLE station_clock_wheel_slots CHANGE algorithm algorithm VARCHAR(30) NOT NULL, CHANGE slot_order slot_order SMALLINT NOT NULL, CHANGE position_seconds position_seconds SMALLINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX idx_scws_wheel TO IDX_A63DB7335D856AB6');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX idx_scws_playlist TO IDX_A63DB7336BBD148');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX fk_clock_wheel_slot_category TO IDX_A63DB73312469DE2');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots CHANGE position_seconds position_seconds SMALLINT UNSIGNED NOT NULL, CHANGE slot_order slot_order SMALLINT NOT NULL');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_clock_wheel_template_slot_template TO IDX_A6A87165DA0FB8');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_clock_wheel_template_slot_playlist TO IDX_A6A87166BBD148');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_clock_wheel_template_slot_category TO IDX_A6A871612469DE2');
        $this->addSql('ALTER TABLE station_clock_wheel_templates CHANGE color color VARCHAR(7) NOT NULL, CHANGE separation_enabled separation_enabled TINYINT NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT NULL, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE station_clock_wheel_templates RENAME INDEX idx_clock_wheel_template_station TO IDX_C2E2E4A521BDB235');
        $this->addSql('DROP INDEX UNIQ_clock_wheel_daypart_hour ON station_clock_wheels');
        $this->addSql('ALTER TABLE station_clock_wheels CHANGE color color VARCHAR(7) NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE fill_strategy fill_strategy VARCHAR(20) NOT NULL, CHANGE separation_enabled separation_enabled TINYINT NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT NULL, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT NULL, CHANGE hour_of_day hour_of_day SMALLINT UNSIGNED DEFAULT NULL, CHANGE inherits_template_slots inherits_template_slots TINYINT NOT NULL');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_scw_station TO IDX_9FD4FD6621BDB235');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_clock_wheel_template TO IDX_9FD4FD665DA0FB8');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_clock_wheel_daypart TO IDX_9FD4FD66120E47B2');
        $this->addSql('ALTER TABLE station_media RENAME INDEX fk_station_media_category TO IDX_32AADE3A12469DE2');
        $this->addSql('ALTER TABLE station_media_categories RENAME INDEX idx_smc_station TO IDX_64C7460021BDB235');
        $this->addSql('ALTER TABLE station_queue CHANGE clock_wheel_enforce_cap clock_wheel_enforce_cap TINYINT NOT NULL');
        $this->addSql('ALTER TABLE station_queue RENAME INDEX idx_station_queue_clock_wheel TO IDX_277B00555D856AB6');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dj DROP FOREIGN KEY FK_EF485E0121BDB235');
        $this->addSql('ALTER TABLE ai_dj_content DROP FOREIGN KEY FK_7C429BAF21BDB235');
        $this->addSql('ALTER TABLE ai_dj_schedules DROP FOREIGN KEY FK_266583EF3BBC1690');
        $this->addSql('DROP TABLE ai_dj');
        $this->addSql('DROP TABLE ai_dj_content');
        $this->addSql('DROP TABLE ai_dj_schedules');
        $this->addSql('ALTER TABLE clock_wheel_events CHANGE event_timestamp event_timestamp DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE expected_play_at expected_play_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE actual_play_at actual_play_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE separation_relaxed separation_relaxed TINYINT DEFAULT 0 NOT NULL, CHANGE burn_rate_warning burn_rate_warning TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE clock_wheel_events RENAME INDEX idx_ae3fb81d59e5119c TO fk_cwe_slot');
        $this->addSql('ALTER TABLE clock_wheel_events RENAME INDEX idx_ae3fb81dea9fdd75 TO fk_cwe_media');
        $this->addSql('ALTER TABLE podcast ADD import_sync_before_hours SMALLINT DEFAULT NULL, CHANGE auto_import_enabled auto_import_enabled TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE song_history RENAME INDEX idx_2ad161645d856ab6 TO IDX_song_history_clock_wheel');
        $this->addSql('ALTER TABLE station ADD static_stream_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE station_clock_dayparts CHANGE start_hour start_hour TINYINT NOT NULL, CHANGE end_hour end_hour TINYINT NOT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE separation_override_enabled separation_override_enabled TINYINT DEFAULT 0 NOT NULL, CHANGE separation_enabled separation_enabled TINYINT DEFAULT 0 NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT 45, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT 90');
        $this->addSql('ALTER TABLE station_clock_dayparts RENAME INDEX idx_7b3d483c21bdb235 TO IDX_clock_daypart_station');
        $this->addSql('ALTER TABLE station_clock_dayparts RENAME INDEX idx_7b3d483c5da0fb8 TO IDX_clock_daypart_template');
        $this->addSql('ALTER TABLE station_clock_wheels CHANGE color color VARCHAR(7) DEFAULT \'#e87722\' NOT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE fill_strategy fill_strategy VARCHAR(20) DEFAULT \'conservative\' NOT NULL, CHANGE separation_enabled separation_enabled TINYINT DEFAULT 0 NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT 45, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT 90, CHANGE hour_of_day hour_of_day TINYINT DEFAULT NULL, CHANGE inherits_template_slots inherits_template_slots TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_clock_wheel_daypart_hour ON station_clock_wheels (daypart_id, hour_of_day)');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_9fd4fd665da0fb8 TO IDX_clock_wheel_template');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_9fd4fd66120e47b2 TO IDX_clock_wheel_daypart');
        $this->addSql('ALTER TABLE station_clock_wheels RENAME INDEX idx_9fd4fd6621bdb235 TO idx_scw_station');
        $this->addSql('ALTER TABLE station_clock_wheel_slots CHANGE algorithm algorithm VARCHAR(30) DEFAULT \'random\' NOT NULL, CHANGE position_seconds position_seconds SMALLINT UNSIGNED DEFAULT 0 NOT NULL, CHANGE slot_order slot_order SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_scws_wheel_position ON station_clock_wheel_slots (clock_wheel_id, position_seconds, slot_order)');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX idx_a63db7335d856ab6 TO idx_scws_wheel');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX idx_a63db7336bbd148 TO idx_scws_playlist');
        $this->addSql('ALTER TABLE station_clock_wheel_slots RENAME INDEX idx_a63db73312469de2 TO fk_clock_wheel_slot_category');
        $this->addSql('ALTER TABLE station_clock_wheel_templates CHANGE color color VARCHAR(7) DEFAULT \'#e87722\' NOT NULL, CHANGE separation_enabled separation_enabled TINYINT DEFAULT 0 NOT NULL, CHANGE separation_artist_minutes separation_artist_minutes SMALLINT UNSIGNED DEFAULT 45, CHANGE separation_title_minutes separation_title_minutes SMALLINT UNSIGNED DEFAULT 90');
        $this->addSql('ALTER TABLE station_clock_wheel_templates RENAME INDEX idx_c2e2e4a521bdb235 TO IDX_clock_wheel_template_station');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots CHANGE position_seconds position_seconds SMALLINT UNSIGNED DEFAULT 0 NOT NULL, CHANGE slot_order slot_order SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_a6a87166bbd148 TO IDX_clock_wheel_template_slot_playlist');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_a6a871612469de2 TO IDX_clock_wheel_template_slot_category');
        $this->addSql('ALTER TABLE station_clock_wheel_template_slots RENAME INDEX idx_a6a87165da0fb8 TO IDX_clock_wheel_template_slot_template');
        $this->addSql('ALTER TABLE station_media RENAME INDEX idx_32aade3a12469de2 TO fk_station_media_category');
        $this->addSql('ALTER TABLE station_media_categories RENAME INDEX idx_64c7460021bdb235 TO idx_smc_station');
        $this->addSql('ALTER TABLE station_queue CHANGE clock_wheel_enforce_cap clock_wheel_enforce_cap TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE station_queue RENAME INDEX idx_277b00555d856ab6 TO IDX_station_queue_clock_wheel');
    }

}
