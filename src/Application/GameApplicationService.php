<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Development\AccountEngine;
use App\Domain\Development\AccountPlanet;
use App\Domain\Development\AccountState;
use App\Domain\Development\ArrivalInputs;
use App\Domain\Development\ColonyRules;
use App\Domain\Development\Coordinates;
use App\Domain\Development\FleetState;
use App\Domain\Development\Mission;
use App\Domain\Development\ResearchEntry;
use App\Domain\Development\Ship;
use App\Domain\Development\ShipyardBatch;
use App\Domain\Development\Technology;
use App\Domain\Economy\Building;
use App\Domain\Economy\BuildingLevels;
use App\Domain\Economy\EconomyDataException;
use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Domain\Economy\Resource;
use App\Domain\Economy\ResourceAmounts;
use App\Entity\User;
use App\Repository\ConstructionEntryRepository;
use App\Repository\PlanetRepository;
use Brick\Math\BigInteger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;

/** Sole transaction coordinator for every durable game-state read/write. */
final readonly class GameApplicationService
{
    public function __construct(
        private ManagerRegistry $registry,
        private AccountEngine $engine,
        private EconomySettings $settings,
        private ClockInterface $clock,
        private PlanetRepository $planets,
        private ConstructionEntryRepository $constructionEntries,
        private \App\Presentation\PlanetViewBuilder $views,
    ) {}

    public function snapshotForOwner(User $owner): AccountState
    {
        $id = $owner->getId();
        if ($id === null) { throw new \InvalidArgumentException('An authenticated persisted account is required.'); }
        return $this->transaction($id, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs): AccountState {
            $transition = $this->engine->advance($state, $now, $inputs);
            if (!$transition->isAccepted()) { throw new DomainRejected($transition->rejection ?? 'invalid_account_transition', $transition->context); }
            return $this->saveTransition($db, $state, $transition->state, $transition->outcomes);
        });
    }

    public function overview(User $owner, int $planetId): array
    {
        $id = $owner->getId();
        if ($id === null) { throw new \InvalidArgumentException('An authenticated persisted account is required.'); }
        $state = $this->transaction($id, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs) use ($planetId): AccountState {
            if (!isset($state->planets[(string) $planetId])) { throw new DomainRejected('planet_not_owned'); }
            $transition = $this->engine->advance($state, $now, $inputs);
            if (!$transition->isAccepted()) { throw new DomainRejected($transition->rejection ?? 'invalid_account_transition', $transition->context); }
            return $this->saveTransition($db, $state, $transition->state, $transition->outcomes);
        });
        $planet = $state->planets[(string) $planetId] ?? null;
        if (!$planet instanceof AccountPlanet) { throw new \OutOfBoundsException('Planet is not owned by this account.'); }
        $em = $this->registry->getManager();
        $em->clear();
        $entity = $this->planets->find($planetId);
        if ($entity === null || (string) $entity->getOwner()->getId() !== (string) $id) { throw new \OutOfBoundsException('Planet is not owned by this account.'); }
        $pending = $this->constructionEntries->findPendingForPlanet($entity);
        $recent = $this->constructionEntries->findRecentForPlanet($entity);
        $view = $this->views->build($entity, $planet->economy, $pending, $recent, $state->settledAt);
        return [...$view, 'account' => ['research' => $state->research, 'planet_count' => count($state->planets), 'home_planet_id' => $this->homePlanetId($id)]];
    }

    public function enqueueConstruction(User $owner, int $planetId, int $buildingId, int $expectedTarget, string $token): GameCommandResponse
    {
        if (!$this->validToken($token)) { return new GameCommandResponse(false, rejectionCode: 'invalid_command_token'); }
        $id = $owner->getId();
        if ($id === null) { return new GameCommandResponse(false, rejectionCode: 'unknown_owner'); }
        try {
            return $this->transaction($id, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs) use ($planetId, $buildingId, $expectedTarget, $token): GameCommandResponse {
                $ref = (string) $planetId;
                if (!isset($state->planets[$ref])) { throw new DomainRejected('unknown_building_or_planet'); }
                $existing = $db->fetchAssociative('SELECT building_id,target_level,status FROM construction_entry WHERE planet_id=? AND command_token=?', [$planetId, $token]);
                if ($existing !== false) {
                    if ((int) $existing['building_id'] !== $buildingId || (int) $existing['target_level'] !== $expectedTarget) { throw new IdempotencyConflict('Construction token payload conflict.'); }
                    return new GameCommandResponse(true, true, (string) $existing['status']);
                }
                $result = $this->engine->enqueueConstruction($state, $ref, $buildingId, $expectedTarget, $token, $now, $inputs);
                if (!$result->isAccepted()) { throw new DomainRejected($result->rejection ?? 'invalid_account_transition', $result->context); }
                $this->saveTransition($db, $state, $result->state, $result->outcomes);
                return new GameCommandResponse(true);
            });
        } catch (DomainRejected $reject) { return new GameCommandResponse(false, rejectionCode: $reject->rejectionCode, context: $reject->context); }
    }

    public function enqueueResearch(User $owner, int $sourcePlanetId, int $technologyId, int $expectedTarget, string $token): GameCommandResponse
    {
        if (!$this->validToken($token)) { return new GameCommandResponse(false, rejectionCode: 'invalid_command_token'); }
        $id = $owner->getId(); if ($id === null) { return new GameCommandResponse(false, rejectionCode: 'unknown_owner'); }
        try {
            return $this->transaction($id, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs) use ($sourcePlanetId, $technologyId, $expectedTarget, $token): GameCommandResponse {
                $existing = $db->fetchAssociative('SELECT source_planet_id,technology_id,target_level,status FROM research_entry WHERE owner_id=? AND command_token=?', [$state->ownerId, $token]);
                if ($existing !== false) {
                    if ((int) $existing['source_planet_id'] !== $sourcePlanetId || (int) $existing['technology_id'] !== $technologyId || (int) $existing['target_level'] !== $expectedTarget) { throw new IdempotencyConflict('Research token payload conflict.'); }
                    return new GameCommandResponse(true, true, (string) $existing['status']);
                }
                $result = $this->engine->enqueueResearch($state, (string) $sourcePlanetId, $technologyId, $expectedTarget, $token, $now, $inputs);
                if (!$result->isAccepted()) { throw new DomainRejected($result->rejection ?? 'invalid_account_transition', $result->context); }
                $this->saveTransition($db, $state, $result->state, $result->outcomes);
                return new GameCommandResponse(true);
            });
        } catch (DomainRejected $reject) { return new GameCommandResponse(false, rejectionCode: $reject->rejectionCode, context: $reject->context); }
    }

    public function enqueueShips(User $owner, int $planetId, int $shipId, int $quantity, string $token): GameCommandResponse
    {
        if (!$this->validToken($token)) { return new GameCommandResponse(false, rejectionCode: 'invalid_command_token'); }
        $id = $owner->getId(); if ($id === null) { return new GameCommandResponse(false, rejectionCode: 'unknown_owner'); }
        try {
            return $this->transaction($id, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs) use ($planetId, $shipId, $quantity, $token): GameCommandResponse {
                $existing = $db->fetchAssociative('SELECT ship_id,quantity,status FROM shipyard_batch WHERE planet_id=? AND command_token=?', [$planetId, $token]);
                if ($existing !== false) {
                    if ((int) $existing['ship_id'] !== $shipId || (int) $existing['quantity'] !== $quantity) { throw new IdempotencyConflict('Shipyard token payload conflict.'); }
                    return new GameCommandResponse(true, true, (string) $existing['status']);
                }
                $result = $this->engine->enqueueShips($state, (string) $planetId, $shipId, $quantity, $token, $now, $inputs);
                if (!$result->isAccepted()) { throw new DomainRejected($result->rejection ?? 'invalid_account_transition', $result->context); }
                $this->saveTransition($db, $state, $result->state, $result->outcomes);
                return new GameCommandResponse(true);
            });
        } catch (DomainRejected $reject) { return new GameCommandResponse(false, rejectionCode: $reject->rejectionCode, context: $reject->context); }
    }

    public function dispatch(User $owner, int $sourcePlanetId, Mission $mission, Coordinates $target, ?int $destinationPlanetId,
        array $ships, array $canonicalStringCargo, int $speedIndex, string $token): GameCommandResponse
    {
        if (!$this->validToken($token)) { return new GameCommandResponse(false, rejectionCode: 'invalid_command_token'); }
        $id = $owner->getId(); if ($id === null) { return new GameCommandResponse(false, rejectionCode: 'unknown_owner'); }
        try {
            return $this->transaction($id, $target, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs) use ($sourcePlanetId, $mission, $target, $destinationPlanetId, $ships, $canonicalStringCargo, $speedIndex, $token): GameCommandResponse {
                $payload = $this->fleetPayload($sourcePlanetId, $mission, $target, $destinationPlanetId, $ships, $canonicalStringCargo, $speedIndex);
                $existing = $db->fetchAssociative('SELECT * FROM fleet WHERE owner_id=? AND command_token=?', [$state->ownerId, $token]);
                if ($existing !== false) {
                    if ($this->fleetPayloadFromRow($existing) !== $payload) { throw new IdempotencyConflict('Fleet token payload conflict.'); }
                    return new GameCommandResponse(true, true, (string) $existing['status']);
                }
                $result = $this->engine->dispatch($state, (string) $sourcePlanetId, $mission, $target,
                    $destinationPlanetId === null ? null : (string) $destinationPlanetId, $ships, $canonicalStringCargo,
                    $speedIndex, $token, $now, $inputs);
                if (!$result->isAccepted()) { throw new DomainRejected($result->rejection ?? 'invalid_account_transition', $result->context); }
                $this->saveTransition($db, $state, $result->state, $result->outcomes);
                return new GameCommandResponse(true);
            });
        } catch (DomainRejected $reject) { return new GameCommandResponse(false, rejectionCode: $reject->rejectionCode, context: $reject->context); }
    }

    public function settleAccountById(int $ownerId): bool
    {
        try {
            return $this->transaction($ownerId, null, function (Connection $db, AccountState $state, array $rows, int $now, ArrivalInputs $inputs): bool {
                $result = $this->engine->advance($state, $now, $inputs);
                if (!$result->isAccepted()) { throw new DomainRejected($result->rejection ?? 'invalid_account_transition', $result->context); }
                $this->saveTransition($db, $state, $result->state, $result->outcomes);
                return true;
            });
        } catch (DomainRejected) { return false; }
    }

    private function transaction(int $ownerId, ?Coordinates $requested, callable $operation): mixed
    {
        $last = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $db = $this->registry->getConnection();
            try {
                $db->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $db->beginTransaction();
                $user = $db->fetchAssociative('SELECT * FROM game_user WHERE id=? FOR UPDATE', [$ownerId]);
                if ($user === false) { throw new DomainRejected('unknown_owner'); }
                $rows = $db->fetchAllAssociative('SELECT * FROM planet WHERE owner_id=? ORDER BY id ASC FOR UPDATE', [$ownerId]);
                if ($rows === []) { throw new \RuntimeException('Account home planet is missing.'); }
                $state = $this->loadState($db, $user, $rows);
                $coordinates = [];
                foreach ($state->fleets as $fleet) { if ($fleet->status === 'outbound') { $coordinates[$fleet->target->key()] = $fleet->target; } }
                if ($requested !== null) { $coordinates[$requested->key()] = $requested; }
                uasort($coordinates, static fn (Coordinates $a, Coordinates $b): int => [$a->galaxy,$a->system,$a->position] <=> [$b->galaxy,$b->system,$b->position]);
                foreach ($coordinates as $coordinate) {
                    $db->executeStatement('INSERT INTO coordinate_lock (universe_id,galaxy,system,position) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE position=VALUES(position)',
                        [$coordinate->galaxy,$coordinate->system,$coordinate->position]);
                }
                $now = $this->clock->now()->getTimestamp();
                $occupied = [];
                foreach ($db->fetchAllAssociative('SELECT galaxy,system,position FROM planet WHERE universe_id=1') as $row) {
                    $occupied[] = (new Coordinates((int) $row['galaxy'],(int) $row['system'],(int) $row['position']))->key();
                }
                $draws = [];
                $rules = new ColonyRules();
                foreach ($state->fleets as $fleet) {
                    if ($fleet->status !== 'outbound' || $fleet->mission !== Mission::Colonize || $fleet->arrivesAt > $now) { continue; }
                    [$low,$high,$fieldLow,$fieldHigh] = $rules->climateBand($fleet->target->position);
                    $max = random_int($low,$high);
                    $draws[$fleet->commandToken] = ['temperature_max' => $max, 'fields_total' => random_int($fieldLow,$fieldHigh)];
                }
                $result = $operation($db,$state,$rows,$now,new ArrivalInputs($occupied,$draws));
                $db->commit();
                return $result;
            } catch (DomainRejected $rejected) {
                if ($db->isTransactionActive()) { $db->rollBack(); }
                throw $rejected;
            } catch (\Throwable $error) {
                if ($db->isTransactionActive()) { $db->rollBack(); }
                if ($error instanceof RetryableException || $this->retryable($error)) {
                    $last = $error;
                    $this->registry->resetManager();
                    continue;
                }
                throw $error;
            }
        }
        throw new \RuntimeException('Account transaction exhausted three fresh attempts.', 0, $last);
    }

    private function loadState(Connection $db, array $user, array $planetRows): AccountState
    {
        $owner = (string) $user['id'];
        $refs = [];
        $planets = [];
        foreach ($planetRows as $row) {
            $ref = (string) $row['id']; $refs[$ref] = true;
            $pending = [];
            foreach ($db->fetchAllAssociative("SELECT * FROM construction_entry WHERE planet_id=? AND status IN ('active','waiting') ORDER BY position", [$row['id']]) as $item) {
                $building = Building::fromLegacyId((int) $item['building_id']) ?? throw new EconomyDataException('Unknown persisted building.');
                $pending[] = $item['status'] === 'active'
                    ? \App\Domain\Economy\ConstructionEntry::active($item['command_token'],$building,(int)$item['target_level'],self::int($item['enqueued_at']),self::int($item['started_at']),self::int($item['completes_at']))
                    : \App\Domain\Economy\ConstructionEntry::waiting($item['command_token'],$building,(int)$item['target_level'],self::int($item['enqueued_at']));
            }
            $levels = [];
            foreach (Building::cases() as $building) { $levels[$building->value] = (int) $row[$building->value.'_level']; }
            $resources = ResourceAmounts::fromCanonical(['metal'=>$row['metal_balance'],'crystal'=>$row['crystal_balance'],'deuterium'=>$row['deuterium_balance']]);
            $economy = new PlanetEconomyState($resources,BuildingLevels::fromCompleteArray($levels), (int)$row['temperature_max'],(int)$row['fields_total'],(int)$row['fields_used'],self::int($row['last_settled_at']),$pending);
            $batches = [];
            foreach ($db->fetchAllAssociative("SELECT * FROM shipyard_batch WHERE planet_id=? AND status IN ('active','waiting') ORDER BY position",[$row['id']]) as $batch) {
                $ship = $this->shipById((int)$batch['ship_id']);
                $cost = ResourceAmounts::fromCanonical(['metal'=>$batch['cost_metal'],'crystal'=>$batch['cost_crystal'],'deuterium'=>$batch['cost_deuterium']]);
                $batches[] = new ShipyardBatch($batch['command_token'],$ship,self::int($batch['quantity']),$cost,self::int($batch['enqueued_at']),$batch['started_at']===null?null:self::int($batch['started_at']),$batch['unit_seconds']===null?null:self::int($batch['unit_seconds']),self::int($batch['produced']),$batch['completes_at']===null?null:self::int($batch['completes_at']),null,$batch['status']);
            }
            $planets[$ref] = new AccountPlanet($ref,new Coordinates((int)$row['galaxy'],(int)$row['system'],(int)$row['position']),self::int($row['born_at']),$economy,
                ['small_cargo'=>self::int($row['small_cargo_count']),'colony_ship'=>self::int($row['colony_ship_count'])],$batches);
        }
        $research = ['spy'=>(int)$user['research_spy'],'energy'=>(int)$user['research_energy'],'combustion'=>(int)$user['research_combustion'],'impulse'=>(int)$user['research_impulse'],'expedition'=>(int)$user['research_expedition']];
        $queue=[];
        foreach ($db->fetchAllAssociative("SELECT * FROM research_entry WHERE owner_id=? AND status IN ('active','waiting') ORDER BY position",[$owner]) as $entry) {
            $technology=$this->technologyById((int)$entry['technology_id']);
            $cost=$entry['started_at']===null?null:ResourceAmounts::fromCanonical(['metal'=>$entry['cost_metal'],'crystal'=>$entry['cost_crystal'],'deuterium'=>$entry['cost_deuterium']]);
            $queue[]=new ResearchEntry($entry['command_token'],$technology,(int)$entry['target_level'],(string)$entry['source_planet_id'],self::int($entry['enqueued_at']),$entry['started_at']===null?null:self::int($entry['started_at']),$entry['completes_at']===null?null:self::int($entry['completes_at']),$cost,$entry['started_at']===null?null:$entry['status'],$entry['resolved_at']===null?null:self::int($entry['resolved_at']),$entry['failure_reason']);
        }
        $fleetRows=$db->fetchAllAssociative("SELECT * FROM fleet WHERE owner_id=? AND (status IN ('outbound','returning') OR id IN (SELECT id FROM (SELECT id FROM fleet WHERE owner_id=? AND status='complete' ORDER BY resolved_at DESC,id DESC LIMIT 20) recent)) ORDER BY id",[$owner,$owner]);
        $fleets=[];
        foreach ($fleetRows as $row) {
            $calc=json_decode((string)$row['calculation_payload'],true,512,JSON_THROW_ON_ERROR);
            $fleets[]=new FleetState($row['command_token'],Mission::from($row['mission']),(string)$row['source_planet_id'],new Coordinates((int)$row['target_galaxy'],(int)$row['target_system'],(int)$row['target_position']),$row['destination_planet_id']===null?null:(string)$row['destination_planet_id'],
                ['small_cargo'=>self::int($row['small_cargo']),'colony_ship'=>self::int($row['colony_ship'])],ResourceAmounts::fromCanonical(['metal'=>$row['metal_cargo'],'crystal'=>$row['crystal_cargo'],'deuterium'=>$row['deuterium_cargo']]),self::int($row['fuel']),self::int($row['departed_at']),self::int($row['arrives_at']),self::int($row['returns_at']),$row['status'],$row['outcome'],$row['colony_planet_id']===null?null:(string)$row['colony_planet_id'],$calc,$row['arrival_resolved_at']===null?null:self::int($row['arrival_resolved_at']),$row['resolved_at']===null?null:self::int($row['resolved_at']),
                ['small_cargo'=>self::int($row['launch_small_cargo']),'colony_ship'=>self::int($row['launch_colony_ship'])],ResourceAmounts::fromCanonical(['metal'=>$row['launch_metal'],'crystal'=>$row['launch_crystal'],'deuterium'=>$row['launch_deuterium']]));
        }
        return new AccountState($owner,self::int($user['settled_at']),$research,$queue,$planets,$fleets);
    }

    private function saveTransition(Connection $db, AccountState $before, AccountState $state, array $outcomes): AccountState
    {
        $newRefs=[];
        foreach ($state->planets as $ref=>$planet) {
            if (str_starts_with((string)$ref,'colony:')) {
                $row=$planet->economy;
                $levels=$row->levels->toArray();
                $resource=$row->resources->toCanonicalArray();
                $db->insert('planet',['owner_id'=>(int)$state->ownerId,'name'=>'Colony','metal_balance'=>$resource['metal'],'crystal_balance'=>$resource['crystal'],'deuterium_balance'=>$resource['deuterium'],
                    'metal_mine_level'=>$levels['metal_mine'],'crystal_mine_level'=>$levels['crystal_mine'],'deuterium_synthesizer_level'=>$levels['deuterium_synthesizer'],'solar_plant_level'=>$levels['solar_plant'],'metal_storage_level'=>$levels['metal_storage'],'crystal_storage_level'=>$levels['crystal_storage'],'deuterium_storage_level'=>$levels['deuterium_storage'],'robotics_factory_level'=>$levels['robotics_factory'],'shipyard_level'=>$levels['shipyard'],'laboratory_level'=>$levels['laboratory'],'temperature_max'=>$row->temperatureMax,'fields_total'=>$row->fieldsTotal,'fields_used'=>$row->fieldsUsed,'last_settled_at'=>(string)$state->settledAt,'universe_id'=>1,'galaxy'=>$planet->coordinates->galaxy,'system'=>$planet->coordinates->system,'position'=>$planet->coordinates->position,'born_at'=>(string)$planet->bornAt,'small_cargo_count'=>'0','colony_ship_count'=>'0']);
                $newRefs[(string)$ref]=(string)$db->lastInsertId();
            }
        }
        $mapRef=static fn(string $ref):string=>$newRefs[$ref]??$ref;
        foreach ($state->planets as $ref=>$planet) {
            $id=$mapRef((string)$ref); $e=$planet->economy; $l=$e->levels->toArray(); $r=$e->resources->toCanonicalArray();
            $db->update('planet',['metal_balance'=>$r['metal'],'crystal_balance'=>$r['crystal'],'deuterium_balance'=>$r['deuterium'],'metal_mine_level'=>$l['metal_mine'],'crystal_mine_level'=>$l['crystal_mine'],'deuterium_synthesizer_level'=>$l['deuterium_synthesizer'],'solar_plant_level'=>$l['solar_plant'],'metal_storage_level'=>$l['metal_storage'],'crystal_storage_level'=>$l['crystal_storage'],'deuterium_storage_level'=>$l['deuterium_storage'],'robotics_factory_level'=>$l['robotics_factory'],'shipyard_level'=>$l['shipyard'],'laboratory_level'=>$l['laboratory'],'temperature_max'=>$e->temperatureMax,'fields_total'=>$e->fieldsTotal,'fields_used'=>$e->fieldsUsed,'last_settled_at'=>(string)$state->settledAt,'born_at'=>(string)$planet->bornAt,'small_cargo_count'=>(string)$planet->ships['small_cargo'],'colony_ship_count'=>(string)$planet->ships['colony_ship']],['id'=>(int)$id]);
        }
        $db->update('game_user',['settled_at'=>(string)$state->settledAt,'research_spy'=>$state->research['spy'],'research_energy'=>$state->research['energy'],'research_combustion'=>$state->research['combustion'],'research_impulse'=>$state->research['impulse'],'research_expedition'=>$state->research['expedition']],['id'=>(int)$state->ownerId]);
        $this->persistConstruction($db,$before,$state,$outcomes,$mapRef);
        $this->persistResearch($db,$before,$state,$outcomes,$mapRef);
        $this->persistBatches($db,$before,$state,$outcomes,$mapRef);
        $this->persistFleets($db,$before,$state,$mapRef,$newRefs);
        if ($newRefs===[]) { return $state; }
        $planets=[];
        foreach ($state->planets as $ref=>$planet) { $newRef=$mapRef((string)$ref); $planets[$newRef]=new AccountPlanet($newRef,$planet->coordinates,$planet->bornAt,$planet->economy,$planet->ships,$planet->batches); }
        $fleets=[];
        foreach ($state->fleets as $fleet) {
            $source=$mapRef($fleet->sourcePlanet); $destination=$fleet->destinationPlanet===null?null:$mapRef($fleet->destinationPlanet);
            $colony=$fleet->colonyReference===null?null:($newRefs['colony:'.$fleet->commandToken]??$fleet->colonyReference);
            $fleets[]=new FleetState($fleet->commandToken,$fleet->mission,$source,$fleet->target,$destination,$fleet->ships,$fleet->cargo,$fleet->fuel,$fleet->departedAt,$fleet->arrivesAt,$fleet->returnsAt,$fleet->status,$fleet->outcome,$colony,$fleet->calculation,$fleet->arrivalResolvedAt,$fleet->resolvedAt,$fleet->launchShips,$fleet->launchCargo);
        }
        return new AccountState($state->ownerId,$state->settledAt,$state->research,$state->researchQueue,$planets,$fleets);
    }

    private function persistConstruction(Connection $db, AccountState $before, AccountState $state, array $outcomes, callable $mapRef): void
    {
        $used=[];
        foreach ($outcomes as $outcome) {
            if (($outcome['type']??null)!=='construction') continue;
            $candidate=null;
            foreach ($before->planets as $ref=>$planet) foreach ($planet->economy->pendingEntries as $pending) {
                $key=(string)$ref.'|'.$pending->commandToken;
                if (isset($used[$key]) || $pending->commandToken!==$outcome['command_token'] || $pending->building->value!==$outcome['building'] || $pending->targetLevel!==$outcome['target_level']) continue;
                $matches=$outcome['status']==='completed'
                    ? ($pending->isActive() && $pending->completesAt===$outcome['completes_at'])
                    : !$pending->isActive();
                if (!$matches) continue;
                $candidate=(int)$mapRef((string)$ref); $used[$key]=true; break 2;
            }
            if ($candidate===null) throw new EconomyDataException('Construction outcome has no unique persisted source row.');
            $db->update('construction_entry',['status'=>$outcome['status'],'started_at'=>$outcome['started_at']===null?null:(string)$outcome['started_at'],'completes_at'=>$outcome['completes_at']===null?null:(string)$outcome['completes_at'],'resolved_at'=>(string)$outcome['resolved_at'],'failure_reason'=>$outcome['failure_reason']],['command_token'=>$outcome['command_token'],'planet_id'=>$candidate]);
        }
        foreach ($state->planets as $ref=>$planet) {
            $id=(int)$mapRef((string)$ref); $existing=[];
            foreach ($db->fetchAllAssociative('SELECT id,command_token,position FROM construction_entry WHERE planet_id=?',[$id]) as $row) $existing[$row['command_token']]=$row;
            $max=(int)($db->fetchOne('SELECT COALESCE(MAX(position),0) FROM construction_entry WHERE planet_id=?',[$id])?:0);
            foreach ($planet->economy->pendingEntries as $position=>$entry) {
                $values=['position'=>$existing[$entry->commandToken]['position']??(string)++$max,'command_token'=>$entry->commandToken,'building_id'=>$entry->building->legacyId(),'target_level'=>$entry->targetLevel,'status'=>$entry->isActive()?'active':'waiting','enqueued_at'=>(string)$entry->enqueuedAt,'started_at'=>$entry->startedAt===null?null:(string)$entry->startedAt,'completes_at'=>$entry->completesAt===null?null:(string)$entry->completesAt,'resolved_at'=>null,'failure_reason'=>null];
                if (isset($existing[$entry->commandToken])) $db->update('construction_entry',$values,['id'=>$existing[$entry->commandToken]['id']]); else $db->insert('construction_entry',['planet_id'=>$id,...$values]);
            }
        }
    }

    private function persistResearch(Connection $db, AccountState $before, AccountState $state, array $outcomes, callable $mapRef): void
    {
        foreach ($state->researchQueue as $position=>$entry) {
            $cost=$entry->cost?->toCanonicalArray()??['metal'=>'0/1','crystal'=>'0/1','deuterium'=>'0/1'];
            $values=['source_planet_id'=>(int)$mapRef($entry->sourcePlanet),'position'=>(string)($position+1),'command_token'=>$entry->commandToken,'technology_id'=>$entry->technology->legacyId(),'target_level'=>$entry->targetLevel,'status'=>$entry->active()?'active':'waiting','enqueued_at'=>(string)$entry->enqueuedAt,'started_at'=>$entry->startedAt===null?null:(string)$entry->startedAt,'completes_at'=>$entry->completesAt===null?null:(string)$entry->completesAt,'resolved_at'=>null,'cost_metal'=>$cost['metal'],'cost_crystal'=>$cost['crystal'],'cost_deuterium'=>$cost['deuterium'],'failure_reason'=>null];
            $id=$db->fetchOne('SELECT id FROM research_entry WHERE owner_id=? AND command_token=?',[(int)$state->ownerId,$entry->commandToken]);
            if ($id===false) $db->insert('research_entry',['owner_id'=>(int)$state->ownerId,...$values]); else $db->update('research_entry',$values,['id'=>$id]);
        }
        foreach ($outcomes as $outcome) { if (($outcome['type']??null)==='research') $db->update('research_entry',['status'=>$outcome['status'],'resolved_at'=>(string)$outcome['resolved_at'],'failure_reason'=>$outcome['failure_reason']??null],['owner_id'=>(int)$state->ownerId,'command_token'=>$outcome['token']]); }
    }

    private function persistBatches(Connection $db, AccountState $before, AccountState $state, array $outcomes, callable $mapRef): void
    {
        foreach ($state->planets as $ref=>$planet) {
            $id=(int)$mapRef((string)$ref);
            foreach ($planet->batches as $position=>$batch) {
                $cost=$batch->cost->toCanonicalArray();
                $values=['position'=>(string)($position+1),'command_token'=>$batch->commandToken,'ship_id'=>$batch->ship->legacyId(),'quantity'=>(string)$batch->quantity,'produced'=>(string)$batch->produced,'cost_metal'=>$cost['metal'],'cost_crystal'=>$cost['crystal'],'cost_deuterium'=>$cost['deuterium'],'status'=>$batch->startedAt===null?'waiting':'active','enqueued_at'=>(string)$batch->enqueuedAt,'started_at'=>$batch->startedAt===null?null:(string)$batch->startedAt,'unit_seconds'=>$batch->unitSeconds===null?null:(string)$batch->unitSeconds,'completes_at'=>$batch->completesAt===null?null:(string)$batch->completesAt,'resolved_at'=>null];
                $old=$db->fetchOne('SELECT id FROM shipyard_batch WHERE planet_id=? AND command_token=?',[$id,$batch->commandToken]);
                if ($old===false) $db->insert('shipyard_batch',['planet_id'=>$id,...$values]); else $db->update('shipyard_batch',$values,['id'=>$old]);
            }
        }
        foreach ($outcomes as $outcome) {
            if (($outcome['type']??null)!=='ship_batch') continue;
            foreach ($before->planets as $ref=>$source) foreach ($source->batches as $batch) {
                if ($batch->commandToken===$outcome['token'] && $batch->completesAt===$outcome['completes_at']) {
                    $db->executeStatement('UPDATE shipyard_batch SET status=?,produced=quantity,resolved_at=? WHERE planet_id=? AND command_token=? AND status=\'active\'', ['completed',(string)$outcome['resolved_at'],(int)$mapRef((string)$ref),$outcome['token']]);
                    break 2;
                }
            }
        }
    }

    private function persistFleets(Connection $db, AccountState $before, AccountState $state, callable $mapRef, array $newRefs): void
    {
        foreach ($state->fleets as $fleet) {
            $calc=json_encode($fleet->calculation,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $cargo=$fleet->cargo->toCanonicalArray(); $launch=$fleet->launchCargo->toCanonicalArray();
            $colonyId=$fleet->colonyReference===null?null:(isset($newRefs['colony:'.$fleet->commandToken])?(int)$newRefs['colony:'.$fleet->commandToken]:(int)$fleet->colonyReference);
            $values=['owner_id'=>(int)$state->ownerId,'command_token'=>$fleet->commandToken,'mission'=>$fleet->mission->value,'source_planet_id'=>(int)$mapRef($fleet->sourcePlanet),'destination_planet_id'=>$fleet->destinationPlanet===null?null:(int)$mapRef($fleet->destinationPlanet),'colony_planet_id'=>$colonyId,'target_galaxy'=>$fleet->target->galaxy,'target_system'=>$fleet->target->system,'target_position'=>$fleet->target->position,'launch_small_cargo'=>$fleet->launchShips['small_cargo'],'launch_colony_ship'=>$fleet->launchShips['colony_ship'],'small_cargo'=>$fleet->ships['small_cargo'],'colony_ship'=>$fleet->ships['colony_ship'],'launch_metal'=>$launch['metal'],'launch_crystal'=>$launch['crystal'],'launch_deuterium'=>$launch['deuterium'],'metal_cargo'=>$cargo['metal'],'crystal_cargo'=>$cargo['crystal'],'deuterium_cargo'=>$cargo['deuterium'],'fuel'=>(string)$fleet->fuel,'departed_at'=>(string)$fleet->departedAt,'arrives_at'=>(string)$fleet->arrivesAt,'returns_at'=>(string)$fleet->returnsAt,'speed_index'=>(int)($fleet->calculation['speed_index']??10),'calculation_payload'=>$calc,'status'=>$fleet->status,'outcome'=>$fleet->outcome,'arrival_resolved_at'=>$fleet->arrivalResolvedAt===null?null:(string)$fleet->arrivalResolvedAt,'resolved_at'=>$fleet->resolvedAt===null?null:(string)$fleet->resolvedAt];
            $row=$db->fetchAssociative('SELECT id FROM fleet WHERE owner_id=? AND command_token=?',[(int)$state->ownerId,$fleet->commandToken]);
            if ($row===false) $db->insert('fleet',$values); else $db->update('fleet',$values,['id'=>$row['id']]);
        }
    }

    private function fleetPayload(int $source, Mission $mission, Coordinates $target, ?int $destination, array $ships, array $cargo, int $speed): array
    {
        ksort($ships); ksort($cargo);
        if (array_keys($cargo)!==['crystal','deuterium','metal'] || !is_string($cargo['metal']??null) || !is_string($cargo['crystal']??null) || !is_string($cargo['deuterium']??null)) throw new \InvalidArgumentException('Malformed fleet replay cargo payload.');
        foreach ($cargo as $value) if (preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$value)!==1) throw new \InvalidArgumentException('Malformed canonical fleet replay cargo.');
        $cargo=ResourceAmounts::fromStrings($cargo['metal'],$cargo['crystal'],$cargo['deuterium'])->toCanonicalArray();
        return ['source'=>$source,'mission'=>$mission->value,'target'=>$target->toArray(),'destination'=>$destination,'ships'=>$ships,'cargo'=>$cargo,'speed_index'=>$speed];
    }
    private function fleetPayloadFromRow(array $row): array
    {
        $cargo=['metal'=>$row['launch_metal'],'crystal'=>$row['launch_crystal'],'deuterium'=>$row['launch_deuterium']];
        $ships=['small_cargo'=>self::int($row['launch_small_cargo']),'colony_ship'=>self::int($row['launch_colony_ship'])];
        return $this->fleetPayload((int)$row['source_planet_id'],Mission::from($row['mission']),new Coordinates((int)$row['target_galaxy'],(int)$row['target_system'],(int)$row['target_position']),$row['destination_planet_id']===null?null:(int)$row['destination_planet_id'],$ships,$cargo,(int)$row['speed_index']);
    }

    private function homePlanetId(int $owner): ?int
    {
        $value=$this->registry->getConnection()->fetchOne('SELECT home_planet_id FROM game_user WHERE id=?',[$owner]);
        return $value===false||$value===null?null:(int)$value;
    }
    private function validToken(string $token): bool { return preg_match('/\A[a-f0-9]{32}\z/D',$token)===1; }
    private function shipById(int $id): Ship { foreach (Ship::cases() as $ship) if ($ship->legacyId()===$id) return $ship; throw new EconomyDataException('Unknown persisted ship.'); }
    private function technologyById(int $id): Technology { foreach (Technology::cases() as $tech) if ($tech->legacyId()===$id) return $tech; throw new EconomyDataException('Unknown persisted technology.'); }
    private static function int(mixed $value): int
    {
        if ((!is_string($value)&&!is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$value)!==1) throw new EconomyDataException('Persisted nonnegative integer is malformed.');
        $number=BigInteger::of((string)$value); if($number->compareTo(PHP_INT_MAX)>0) throw new EconomyDataException('Persisted integer exceeds platform range.'); return $number->toInt();
    }
    private function retryable(\Throwable $error): bool
    {
        for($e=$error;$e!==null;$e=$e->getPrevious()) { if ((string)$e->getCode()==='40001' || (string)$e->getCode()==='1213' || (string)$e->getCode()==='1205') return true; }
        return false;
    }
}

final class DomainRejected extends \RuntimeException
{
    public function __construct(public readonly string $rejectionCode, public readonly array $context = []) { parent::__construct($rejectionCode); }
}
