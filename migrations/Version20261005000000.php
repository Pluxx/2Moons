<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the first playable-economy account, planet, and durable construction queue tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE game_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_game_user_email (email), PRIMARY KEY(id)) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE planet (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, name VARCHAR(80) NOT NULL, metal_balance LONGTEXT NOT NULL, crystal_balance LONGTEXT NOT NULL, deuterium_balance LONGTEXT NOT NULL, metal_mine_level SMALLINT UNSIGNED NOT NULL, crystal_mine_level SMALLINT UNSIGNED NOT NULL, deuterium_synthesizer_level SMALLINT UNSIGNED NOT NULL, solar_plant_level SMALLINT UNSIGNED NOT NULL, metal_storage_level SMALLINT UNSIGNED NOT NULL, crystal_storage_level SMALLINT UNSIGNED NOT NULL, deuterium_storage_level SMALLINT UNSIGNED NOT NULL, temperature_max SMALLINT NOT NULL, fields_total SMALLINT UNSIGNED NOT NULL, fields_used SMALLINT UNSIGNED NOT NULL, last_settled_at BIGINT NOT NULL, UNIQUE INDEX UNIQ_68136AA57E3C61F9 (owner_id), PRIMARY KEY(id), CONSTRAINT chk_planet_completed_fields CHECK (fields_used = metal_mine_level + crystal_mine_level + deuterium_synthesizer_level + solar_plant_level + metal_storage_level + crystal_storage_level + deuterium_storage_level), CONSTRAINT fk_planet_owner FOREIGN KEY (owner_id) REFERENCES game_user (id) ON DELETE CASCADE) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE construction_entry (id BIGINT AUTO_INCREMENT NOT NULL, planet_id INT NOT NULL, position BIGINT NOT NULL, command_token VARCHAR(32) NOT NULL, building_id SMALLINT UNSIGNED NOT NULL, target_level SMALLINT UNSIGNED NOT NULL, status VARCHAR(10) NOT NULL, enqueued_at BIGINT NOT NULL, started_at BIGINT DEFAULT NULL, completes_at BIGINT DEFAULT NULL, resolved_at BIGINT DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_construction_planet_position (planet_id, position), UNIQUE INDEX uniq_construction_planet_token (planet_id, command_token), INDEX idx_construction_pending (planet_id, status, position), INDEX idx_construction_due (status, completes_at), PRIMARY KEY(id), CONSTRAINT chk_construction_status CHECK (status IN ('waiting', 'active', 'completed', 'failed')), CONSTRAINT chk_construction_timestamps CHECK ((status = 'waiting' AND started_at IS NULL AND completes_at IS NULL AND resolved_at IS NULL AND failure_reason IS NULL) OR (status = 'active' AND started_at IS NOT NULL AND completes_at IS NOT NULL AND resolved_at IS NULL AND failure_reason IS NULL) OR (status = 'completed' AND started_at IS NOT NULL AND completes_at IS NOT NULL AND resolved_at IS NOT NULL AND failure_reason IS NULL) OR (status = 'failed' AND started_at IS NULL AND completes_at IS NULL AND resolved_at IS NOT NULL AND failure_reason IS NOT NULL)), CONSTRAINT fk_construction_planet FOREIGN KEY (planet_id) REFERENCES planet (id) ON DELETE CASCADE) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE construction_entry');
        $this->addSql('DROP TABLE planet');
        $this->addSql('DROP TABLE game_user');
    }
}
