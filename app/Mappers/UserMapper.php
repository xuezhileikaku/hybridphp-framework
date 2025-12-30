<?php

declare(strict_types=1);

namespace App\Mappers;

use App\Entities\UserEntity;
use HybridPHP\Core\Database\ORM\DataMapper;
use Amp\Future;
use function Amp\async;

/**
 * User DataMapper implementation
 */
class UserMapper extends DataMapper
{
    /**
     * Initialize mapper
     */
    protected function initialize(): void
    {
        $this->setTableName('users');
        $this->setEntityClass(UserEntity::class);
        $this->setPrimaryKey('id');
        $this->setFieldMapping([
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
        ]);
    }

    /**
     * Find user by username
     */
    public function findByUsername(string $username): Future
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): Future
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Find active users
     */
    public function findActiveUsers(): Future
    {
        return $this->findBy(['status' => 1], ['username' => 'ASC']);
    }

    /**
     * Find users created after date
     */
    public function findUsersCreatedAfter(\DateTime $date): Future
    {
        return async(function () use ($date) {
            $query = new \HybridPHP\Core\Database\QueryBuilder($this->db);
            $results = $query->table($this->tableName)
                ->where('created_at', '>', $date->format('Y-m-d H:i:s'))
                ->orderBy('created_at', 'DESC')
                ->get()->await();

            return array_map([$this, 'mapToEntity'], $results);
        });
    }

    /**
     * Convert value from database format
     */
    protected function convertFromDatabase(string $property, $value)
    {
        switch ($property) {
            case 'createdAt':
            case 'updatedAt':
                return $value ? new \DateTime($value) : null;
            case 'status':
                return (int)$value;
            default:
                return $value;
        }
    }

    /**
     * Convert value to database format
     */
    protected function convertToDatabase(string $property, $value)
    {
        switch ($property) {
            case 'createdAt':
            case 'updatedAt':
                return $value instanceof \DateTime ? $value->format('Y-m-d H:i:s') : $value;
            default:
                return $value;
        }
    }

    /**
     * Override save to handle timestamps
     */
    public function save(object $entity): Future
    {
        return async(function () use ($entity) {
            if (!($entity instanceof UserEntity)) {
                throw new \InvalidArgumentException('Entity must be UserEntity instance');
            }

            $now = new \DateTime();
            
            if ($entity->getId() === null) {
                $entity->setCreatedAt($now);
            }
            $entity->setUpdatedAt($now);

            return parent::save($entity)->await();
        });
    }
}