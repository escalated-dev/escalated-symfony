<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\EscalatedSetting;

/**
 * @extends ServiceEntityRepository<EscalatedSetting>
 *
 * @method EscalatedSetting|null find($id, $lockMode = null, $lockVersion = null)
 * @method EscalatedSetting|null findOneBy(array $criteria, array $orderBy = null)
 * @method EscalatedSetting[]    findAll()
 * @method EscalatedSetting[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EscalatedSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscalatedSetting::class);
    }
}
