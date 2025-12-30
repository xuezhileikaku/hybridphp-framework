<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * User entity for DataMapper pattern
 */
class UserEntity
{
    public ?int $id = null;
    public ?string $username = null;
    public ?string $email = null;
    public ?string $password = null;
    public int $status = 1;
    public ?\DateTime $createdAt = null;
    public ?\DateTime $updatedAt = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $property => $value) {
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
    }

    /**
     * Get ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set ID
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Get username
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Set username
     */
    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    /**
     * Get email
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Set email
     */
    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    /**
     * Get password
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Set password
     */
    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    /**
     * Get status
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Set status
     */
    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * Get created at
     */
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    /**
     * Set created at
     */
    public function setCreatedAt(?\DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get updated at
     */
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Set updated at
     */
    public function setUpdatedAt(?\DateTime $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->status === 1;
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * Hash and set password
     */
    public function hashPassword(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_DEFAULT);
    }
}