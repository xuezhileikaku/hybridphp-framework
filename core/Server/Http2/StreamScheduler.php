<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Stream Scheduler
 * 
 * Implements priority-based stream scheduling according to RFC 7540 Section 5.3.
 * Uses a dependency tree with weighted fair queuing for bandwidth allocation.
 * 
 * Features:
 * - Dependency tree management
 * - Weighted fair queuing
 * - Exclusive dependencies
 * - Dynamic priority updates
 */
class StreamScheduler
{
    /** @var array<int, StreamPriority> Stream priorities */
    private array $priorities = [];
    
    /** @var array<int, int[]> Children of each stream (dependency tree) */
    private array $children = [];
    
    /** @var array<int, bool> Streams ready to send */
    private array $readyStreams = [];
    
    /** @var int Root stream ID (virtual) */
    private const ROOT_STREAM = 0;

    public function __construct()
    {
        // Initialize root node
        $this->children[self::ROOT_STREAM] = [];
    }

    /**
     * Add a stream with priority
     */
    public function addStream(int $streamId, int $weight = 16, int $dependency = 0, bool $exclusive = false): void
    {
        // Validate weight (1-256)
        $weight = max(1, min(256, $weight));
        
        // Create priority entry
        $this->priorities[$streamId] = new StreamPriority($streamId, $weight, $dependency, $exclusive);
        
        // Handle exclusive dependency
        if ($exclusive && $dependency >= 0) {
            $this->handleExclusiveDependency($streamId, $dependency);
        } else {
            // Add as child of dependency
            $this->addChild($dependency, $streamId);
        }
    }

    /**
     * Remove a stream
     */
    public function removeStream(int $streamId): void
    {
        if (!isset($this->priorities[$streamId])) {
            return;
        }
        
        $priority = $this->priorities[$streamId];
        $parent = $priority->getDependency();
        
        // Move children to parent
        if (isset($this->children[$streamId])) {
            foreach ($this->children[$streamId] as $childId) {
                if (isset($this->priorities[$childId])) {
                    $this->priorities[$childId]->setDependency($parent);
                }
                $this->addChild($parent, $childId);
            }
            unset($this->children[$streamId]);
        }
        
        // Remove from parent's children
        $this->removeChild($parent, $streamId);
        
        unset($this->priorities[$streamId]);
        unset($this->readyStreams[$streamId]);
    }

    /**
     * Update stream priority
     */
    public function updatePriority(int $streamId, int $weight, int $dependency = null, bool $exclusive = false): void
    {
        if (!isset($this->priorities[$streamId])) {
            $this->addStream($streamId, $weight, $dependency ?? 0, $exclusive);
            return;
        }
        
        $priority = $this->priorities[$streamId];
        $oldDependency = $priority->getDependency();
        
        // Update weight
        $priority->setWeight(max(1, min(256, $weight)));
        
        // Update dependency if changed
        if ($dependency !== null && $dependency !== $oldDependency) {
            // Prevent circular dependency
            if ($this->wouldCreateCycle($streamId, $dependency)) {
                // Move dependency's parent to stream's parent
                $depPriority = $this->priorities[$dependency] ?? null;
                if ($depPriority) {
                    $this->removeChild($depPriority->getDependency(), $dependency);
                    $this->addChild($oldDependency, $dependency);
                    $depPriority->setDependency($oldDependency);
                }
            }
            
            // Remove from old parent
            $this->removeChild($oldDependency, $streamId);
            
            // Handle exclusive
            if ($exclusive) {
                $this->handleExclusiveDependency($streamId, $dependency);
            } else {
                $this->addChild($dependency, $streamId);
            }
            
            $priority->setDependency($dependency);
        }
        
        $priority->setExclusive($exclusive);
    }


    /**
     * Mark stream as ready to send
     */
    public function markReady(int $streamId): void
    {
        if (isset($this->priorities[$streamId])) {
            $this->readyStreams[$streamId] = true;
        }
    }

    /**
     * Mark stream as not ready
     */
    public function markNotReady(int $streamId): void
    {
        unset($this->readyStreams[$streamId]);
    }

    /**
     * Get next stream to process based on priority
     * 
     * Uses weighted fair queuing: streams with higher weights
     * get proportionally more bandwidth.
     */
    public function getNextStream(): ?int
    {
        if (empty($this->readyStreams)) {
            return null;
        }
        
        // Start from root and traverse dependency tree
        return $this->selectFromSubtree(self::ROOT_STREAM);
    }

    /**
     * Select next stream from a subtree using weighted selection
     */
    private function selectFromSubtree(int $parentId): ?int
    {
        $children = $this->children[$parentId] ?? [];
        
        if (empty($children)) {
            return null;
        }
        
        // Collect ready streams and their weights
        $candidates = [];
        $totalWeight = 0;
        
        foreach ($children as $childId) {
            // Check if this stream or any descendant is ready
            if ($this->hasReadyDescendant($childId)) {
                $weight = $this->priorities[$childId]->getWeight();
                $candidates[$childId] = $weight;
                $totalWeight += $weight;
            }
        }
        
        if (empty($candidates)) {
            return null;
        }
        
        // Weighted random selection
        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;
        
        foreach ($candidates as $childId => $weight) {
            $cumulative += $weight;
            if ($random <= $cumulative) {
                // If this stream is ready, return it
                if (isset($this->readyStreams[$childId])) {
                    return $childId;
                }
                // Otherwise, recurse into subtree
                return $this->selectFromSubtree($childId);
            }
        }
        
        // Fallback: return first ready stream
        foreach ($candidates as $childId => $weight) {
            if (isset($this->readyStreams[$childId])) {
                return $childId;
            }
            $result = $this->selectFromSubtree($childId);
            if ($result !== null) {
                return $result;
            }
        }
        
        return null;
    }

    /**
     * Check if stream or any descendant is ready
     */
    private function hasReadyDescendant(int $streamId): bool
    {
        if (isset($this->readyStreams[$streamId])) {
            return true;
        }
        
        foreach ($this->children[$streamId] ?? [] as $childId) {
            if ($this->hasReadyDescendant($childId)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Handle exclusive dependency
     */
    private function handleExclusiveDependency(int $streamId, int $dependency): void
    {
        // Get current children of dependency
        $existingChildren = $this->children[$dependency] ?? [];
        
        // Make stream the only child of dependency
        $this->children[$dependency] = [$streamId];
        
        // Make existing children depend on new stream
        $this->children[$streamId] = $existingChildren;
        
        // Update priorities of moved children
        foreach ($existingChildren as $childId) {
            if (isset($this->priorities[$childId])) {
                $this->priorities[$childId]->setDependency($streamId);
            }
        }
    }

    /**
     * Check if adding dependency would create a cycle
     */
    private function wouldCreateCycle(int $streamId, int $dependency): bool
    {
        if ($dependency === self::ROOT_STREAM) {
            return false;
        }
        
        $current = $dependency;
        $visited = [];
        
        while ($current !== self::ROOT_STREAM) {
            if ($current === $streamId || isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $current = $this->priorities[$current]->getDependency() ?? self::ROOT_STREAM;
        }
        
        return false;
    }

    /**
     * Add child to parent
     */
    private function addChild(int $parent, int $child): void
    {
        if (!isset($this->children[$parent])) {
            $this->children[$parent] = [];
        }
        if (!in_array($child, $this->children[$parent])) {
            $this->children[$parent][] = $child;
        }
    }

    /**
     * Remove child from parent
     */
    private function removeChild(int $parent, int $child): void
    {
        if (isset($this->children[$parent])) {
            $this->children[$parent] = array_values(
                array_filter($this->children[$parent], fn($id) => $id !== $child)
            );
        }
    }

    /**
     * Get stream priority
     */
    public function getPriority(int $streamId): ?StreamPriority
    {
        return $this->priorities[$streamId] ?? null;
    }

    /**
     * Get all ready streams
     */
    public function getReadyStreams(): array
    {
        return array_keys($this->readyStreams);
    }

    /**
     * Get dependency tree as array
     */
    public function getDependencyTree(): array
    {
        return $this->buildTreeArray(self::ROOT_STREAM);
    }

    /**
     * Build tree array recursively
     */
    private function buildTreeArray(int $parentId): array
    {
        $result = [];
        
        foreach ($this->children[$parentId] ?? [] as $childId) {
            $priority = $this->priorities[$childId] ?? null;
            $result[$childId] = [
                'weight' => $priority ? $priority->getWeight() : 16,
                'exclusive' => $priority ? $priority->isExclusive() : false,
                'ready' => isset($this->readyStreams[$childId]),
                'children' => $this->buildTreeArray($childId),
            ];
        }
        
        return $result;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return [
            'total_streams' => count($this->priorities),
            'ready_streams' => count($this->readyStreams),
            'tree_depth' => $this->calculateTreeDepth(self::ROOT_STREAM),
        ];
    }

    /**
     * Calculate tree depth
     */
    private function calculateTreeDepth(int $parentId): int
    {
        $maxDepth = 0;
        
        foreach ($this->children[$parentId] ?? [] as $childId) {
            $depth = 1 + $this->calculateTreeDepth($childId);
            $maxDepth = max($maxDepth, $depth);
        }
        
        return $maxDepth;
    }
}
