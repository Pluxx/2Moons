<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Economy\EconomySettings;
use App\Domain\Economy\PlanetEconomyState;
use App\Entity\Planet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegistrationService
{
    public function __construct(
        private ManagerRegistry $registry,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private EconomySettings $settings,
    ) {
    }

    public function register(User $user, string $plainPassword): User
    {
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));
        $email=$user->getEmail(); $passwordHash=$user->getPassword();
        $last=null;
        for($attempt=0;$attempt<3;++$attempt) {
            $entityManager=$this->registry->getManager();
            $connection=$entityManager->getConnection();
            try {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->beginTransaction();
                $sequence=$connection->fetchOne('SELECT next_home_index FROM universe WHERE id=1 FOR UPDATE');
                if($sequence===false) throw new \RuntimeException('Universe allocator row 1 is missing.');
                $index=filter_var($sequence,FILTER_VALIDATE_INT);
                if(!is_int($index)||$index<0||$index>=36000) throw new \RuntimeException('Universe home allocation capacity is exhausted or malformed.');
                $coordinates=null;
                for(;$index<36000;++$index){
                    $candidate=new \App\Domain\Development\Coordinates(1+intdiv($index,4000),1+(intdiv($index,10)%400),3+($index%10));
                    $connection->executeStatement('INSERT INTO coordinate_lock (universe_id,galaxy,system,position) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE position=VALUES(position)',[$candidate->galaxy,$candidate->system,$candidate->position]);
                    $occupied=$connection->fetchOne('SELECT id FROM planet WHERE universe_id=1 AND galaxy=? AND system=? AND position=?',[$candidate->galaxy,$candidate->system,$candidate->position]);
                    if($occupied===false){$coordinates=$candidate;++$index;break;}
                }
                if($coordinates===null) throw new \RuntimeException('Universe home allocation capacity is exhausted.');
                $connection->update('universe',['next_home_index'=>(string)$index],['id'=>1]);
                $now=$this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                $attemptUser=$attempt===0?$user:new User();
                if($attempt>0){$attemptUser->setEmail($email);$attemptUser->setPasswordHash($passwordHash);}
                $attemptUser->setCreatedAt($now);
                $attemptUser->setAccountState($now->getTimestamp(),['spy'=>0,'energy'=>0,'combustion'=>0,'impulse'=>0,'expedition'=>0]);
                $planet=new Planet($attemptUser,PlanetEconomyState::newHome($now->getTimestamp(),$this->settings),$coordinates,$now->getTimestamp());
                $attemptUser->setHomePlanet($planet);
                $entityManager->persist($attemptUser);$entityManager->persist($planet);$entityManager->flush();
                $connection->commit();
                return $attemptUser;
            } catch(\Throwable $error) {
                if($connection->isTransactionActive())$connection->rollBack();
                if(!$this->retryable($error)||$attempt===2) throw $error;
                $last=$error;$this->registry->resetManager();
            }
        }
        throw new \RuntimeException('Registration exhausted three fresh attempts.',0,$last);
    }

    private function retryable(\Throwable $error): bool
    {
        for($e=$error;$e!==null;$e=$e->getPrevious())if((string)$e->getCode()==='40001'||(string)$e->getCode()==='1213'||(string)$e->getCode()==='1205')return true;
        return $error instanceof \Doctrine\DBAL\Exception\RetryableException;
    }
}
