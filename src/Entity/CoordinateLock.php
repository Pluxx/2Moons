<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'coordinate_lock')]
class CoordinateLock
{
    #[ORM\Id]
    #[ORM\Column(name: 'universe_id', type: 'smallint', options: ['unsigned' => true])]
    private int $universeId;
    #[ORM\Id]
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $galaxy;
    #[ORM\Id]
    #[ORM\Column(name: '`system`', type: 'smallint', options: ['unsigned' => true])]
    private int $system;
    #[ORM\Id]
    #[ORM\Column(name: '`position`', type: 'smallint', options: ['unsigned' => true])]
    private int $position;
}
