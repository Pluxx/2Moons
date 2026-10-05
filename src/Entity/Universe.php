<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'universe')]
class Universe
{
    #[ORM\Id]
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $id;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $galaxies;
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $systems;
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $positions;
    #[ORM\Column(name: 'next_home_index', type: 'bigint')]
    private string $nextHomeIndex;
}
