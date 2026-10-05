<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand durable game state to account-scoped planets, research, shipyard and fleets.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet') > 36000,
            'Home allocation exceeds the 9×400×10 deterministic home-position capacity.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_user u LEFT JOIN planet p ON p.owner_id = u.id WHERE p.id IS NULL') !== 0,
            'Every existing account must have one home planet before migration.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM planet WHERE fields_used <> metal_mine_level + crystal_mine_level + deuterium_synthesizer_level + solar_plant_level + metal_storage_level + crystal_storage_level + deuterium_storage_level') !== 0,
            'Existing completed building levels do not reconcile with occupied fields.');
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM planet WHERE metal_balance NOT REGEXP '^(0|[1-9][0-9]*)/[1-9][0-9]*$' OR crystal_balance NOT REGEXP '^(0|[1-9][0-9]*)/[1-9][0-9]*$' OR deuterium_balance NOT REGEXP '^(0|[1-9][0-9]*)/[1-9][0-9]*$'") !== 0,
            'Existing balances are not canonical rational strings.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM construction_entry WHERE (status = \'active\' AND (started_at IS NULL OR completes_at IS NULL OR resolved_at IS NOT NULL)) OR (status = \'waiting\' AND (started_at IS NOT NULL OR completes_at IS NOT NULL OR resolved_at IS NOT NULL))') !== 0,
            'Existing construction queue metadata is inconsistent.');

        $this->addSql("CREATE TABLE universe (id SMALLINT UNSIGNED NOT NULL, galaxies SMALLINT UNSIGNED NOT NULL, systems SMALLINT UNSIGNED NOT NULL, positions SMALLINT UNSIGNED NOT NULL, next_home_index BIGINT NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_universe_singleton CHECK (id = 1), CONSTRAINT chk_universe_bounds CHECK (galaxies = 9 AND systems = 400 AND positions = 15)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql('INSERT INTO universe (id, galaxies, systems, positions, next_home_index) VALUES (1, 9, 400, 15, 0)');
        $this->addSql("CREATE TABLE coordinate_lock (universe_id SMALLINT UNSIGNED NOT NULL, galaxy SMALLINT UNSIGNED NOT NULL, system SMALLINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL, PRIMARY KEY(universe_id,galaxy,system,position), CONSTRAINT fk_coordinate_lock_universe FOREIGN KEY (universe_id) REFERENCES universe(id) ON DELETE CASCADE, CONSTRAINT chk_coordinate_lock_bounds CHECK (galaxy BETWEEN 1 AND 9 AND system BETWEEN 1 AND 400 AND position BETWEEN 1 AND 15)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql('ALTER TABLE game_user ADD settled_at BIGINT NOT NULL DEFAULT 0, ADD research_spy SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD research_energy SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD research_combustion SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD research_impulse SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD research_expedition SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD home_planet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE planet DROP FOREIGN KEY fk_planet_owner, DROP INDEX UNIQ_68136AA57E3C61F9, ADD COLUMN universe_id SMALLINT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN galaxy SMALLINT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `system` SMALLINT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `position` SMALLINT UNSIGNED NOT NULL DEFAULT 3, ADD COLUMN born_at BIGINT NOT NULL DEFAULT 0, ADD COLUMN robotics_factory_level SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN shipyard_level SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN laboratory_level SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN small_cargo_count BIGINT NOT NULL DEFAULT 0, ADD COLUMN colony_ship_count BIGINT NOT NULL DEFAULT 0');
        $this->addSql('CREATE TEMPORARY TABLE migration_home_sequence (planet_id INT NOT NULL PRIMARY KEY, seq BIGINT NOT NULL) ENGINE=InnoDB');
        $this->addSql('INSERT INTO migration_home_sequence (planet_id, seq) SELECT id, ROW_NUMBER() OVER (ORDER BY id) - 1 FROM planet');
        $this->addSql('UPDATE planet p JOIN migration_home_sequence s ON s.planet_id = p.id SET p.galaxy = FLOOR(s.seq / 4000) + 1, p.`system` = FLOOR(s.seq / 10) MOD 400 + 1, p.`position` = 3 + (s.seq MOD 10), p.born_at = p.last_settled_at');
        $this->addSql('DROP TEMPORARY TABLE migration_home_sequence');
        $this->addSql('UPDATE game_user u JOIN planet p ON p.owner_id = u.id SET u.home_planet_id = p.id, u.settled_at = p.last_settled_at');
        $this->addSql('ALTER TABLE planet ADD UNIQUE INDEX uniq_planet_coordinate (universe_id, galaxy, `system`, `position`), ADD INDEX idx_planet_owner (owner_id,id), DROP CONSTRAINT chk_planet_completed_fields, ADD CONSTRAINT chk_planet_completed_fields CHECK (fields_used = metal_mine_level + crystal_mine_level + deuterium_synthesizer_level + solar_plant_level + metal_storage_level + crystal_storage_level + deuterium_storage_level + robotics_factory_level + shipyard_level + laboratory_level), ADD CONSTRAINT fk_planet_owner FOREIGN KEY (owner_id) REFERENCES game_user (id) ON DELETE CASCADE, ADD CONSTRAINT fk_planet_universe FOREIGN KEY (universe_id) REFERENCES universe(id)');
        $this->addSql('ALTER TABLE game_user ADD UNIQUE INDEX uniq_game_user_home (home_planet_id), ADD CONSTRAINT fk_user_home_planet FOREIGN KEY (home_planet_id) REFERENCES planet(id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE research_entry (id BIGINT NOT NULL AUTO_INCREMENT, owner_id INT NOT NULL, source_planet_id INT NOT NULL, position BIGINT NOT NULL, command_token VARCHAR(32) NOT NULL, technology_id SMALLINT UNSIGNED NOT NULL, target_level SMALLINT UNSIGNED NOT NULL, status VARCHAR(10) NOT NULL, enqueued_at BIGINT NOT NULL, started_at BIGINT DEFAULT NULL, completes_at BIGINT DEFAULT NULL, resolved_at BIGINT DEFAULT NULL, cost_metal LONGTEXT NOT NULL, cost_crystal LONGTEXT NOT NULL, cost_deuterium LONGTEXT NOT NULL, failure_reason VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id), UNIQUE INDEX uniq_research_owner_token(owner_id,command_token), UNIQUE INDEX uniq_research_owner_position(owner_id,position), INDEX idx_research_pending(owner_id,status,position), INDEX idx_research_due(status,completes_at), CONSTRAINT fk_research_owner FOREIGN KEY(owner_id) REFERENCES game_user(id) ON DELETE CASCADE, CONSTRAINT fk_research_source FOREIGN KEY(source_planet_id) REFERENCES planet(id) ON DELETE CASCADE, CONSTRAINT chk_research_status CHECK(status IN ('waiting','active','completed','failed'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE shipyard_batch (id BIGINT NOT NULL AUTO_INCREMENT, planet_id INT NOT NULL, position BIGINT NOT NULL, command_token VARCHAR(32) NOT NULL, ship_id SMALLINT UNSIGNED NOT NULL, quantity BIGINT NOT NULL, produced BIGINT NOT NULL, cost_metal LONGTEXT NOT NULL, cost_crystal LONGTEXT NOT NULL, cost_deuterium LONGTEXT NOT NULL, status VARCHAR(10) NOT NULL, enqueued_at BIGINT NOT NULL, started_at BIGINT DEFAULT NULL, unit_seconds BIGINT DEFAULT NULL, completes_at BIGINT DEFAULT NULL, resolved_at BIGINT DEFAULT NULL, PRIMARY KEY(id), UNIQUE INDEX uniq_batch_planet_token(planet_id,command_token), UNIQUE INDEX uniq_batch_planet_position(planet_id,position), INDEX idx_batch_pending(planet_id,status,position), INDEX idx_batch_due(status,completes_at), CONSTRAINT fk_batch_planet FOREIGN KEY(planet_id) REFERENCES planet(id) ON DELETE CASCADE, CONSTRAINT chk_batch_status CHECK(status IN ('waiting','active','completed'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE fleet (id BIGINT NOT NULL AUTO_INCREMENT, owner_id INT NOT NULL, command_token VARCHAR(32) NOT NULL, mission VARCHAR(10) NOT NULL, source_planet_id INT NOT NULL, destination_planet_id INT DEFAULT NULL, colony_planet_id INT DEFAULT NULL, target_galaxy SMALLINT UNSIGNED NOT NULL, target_system SMALLINT UNSIGNED NOT NULL, target_position SMALLINT UNSIGNED NOT NULL, launch_small_cargo BIGINT NOT NULL, launch_colony_ship BIGINT NOT NULL, small_cargo BIGINT NOT NULL, colony_ship BIGINT NOT NULL, launch_metal LONGTEXT NOT NULL, launch_crystal LONGTEXT NOT NULL, launch_deuterium LONGTEXT NOT NULL, metal_cargo LONGTEXT NOT NULL, crystal_cargo LONGTEXT NOT NULL, deuterium_cargo LONGTEXT NOT NULL, fuel BIGINT NOT NULL, departed_at BIGINT NOT NULL, arrives_at BIGINT NOT NULL, returns_at BIGINT NOT NULL, speed_index SMALLINT UNSIGNED NOT NULL, calculation_payload LONGTEXT NOT NULL, status VARCHAR(10) NOT NULL, outcome VARCHAR(64) DEFAULT NULL, arrival_resolved_at BIGINT DEFAULT NULL, resolved_at BIGINT DEFAULT NULL, PRIMARY KEY(id), UNIQUE INDEX uniq_fleet_owner_token(owner_id,command_token), INDEX idx_fleet_pending(owner_id,status,arrives_at,returns_at), INDEX idx_fleet_recent(owner_id,resolved_at), CONSTRAINT fk_fleet_owner FOREIGN KEY(owner_id) REFERENCES game_user(id) ON DELETE CASCADE, CONSTRAINT fk_fleet_source FOREIGN KEY(source_planet_id) REFERENCES planet(id), CONSTRAINT fk_fleet_destination FOREIGN KEY(destination_planet_id) REFERENCES planet(id) ON DELETE SET NULL, CONSTRAINT fk_fleet_colony FOREIGN KEY(colony_planet_id) REFERENCES planet(id) ON DELETE SET NULL, CONSTRAINT chk_fleet_mission CHECK(mission IN ('transport','colonize')), CONSTRAINT chk_fleet_status CHECK(status IN ('outbound','returning','complete'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql('UPDATE universe SET next_home_index = (SELECT COUNT(*) FROM planet) WHERE id = 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The account expansion is additive and cannot be safely reversed after new planets or events are written.');
    }
}
