<?php

namespace HybridPHP\Core\Cache;

/**
 * Consistent Hash implementation for distributed caching
 */
class ConsistentHash
{
    private array $nodes = [];
    private array $ring = [];
    private int $virtualNodes = 150;

    public function __construct(array $nodes, int $virtualNodes = 150)
    {
        $this->virtualNodes = $virtualNodes;
        $this->setNodes($nodes);
    }

    /**
     * Set nodes and build hash ring
     */
    public function setNodes(array $nodes): void
    {
        $this->nodes = [];
        $this->ring = [];

        foreach ($nodes as $nodeKey => $node) {
            $this->addNode($nodeKey);
        }

        ksort($this->ring);
    }

    /**
     * Add a node to the hash ring
     */
    public function addNode(string $nodeKey): void
    {
        $this->nodes[$nodeKey] = true;

        for ($i = 0; $i < $this->virtualNodes; $i++) {
            $virtualKey = $this->hash($nodeKey . ':' . $i);
            $this->ring[$virtualKey] = $nodeKey;
        }

        ksort($this->ring);
    }

    /**
     * Remove a node from the hash ring
     */
    public function removeNode(string $nodeKey): void
    {
        if (!isset($this->nodes[$nodeKey])) {
            return;
        }

        unset($this->nodes[$nodeKey]);

        for ($i = 0; $i < $this->virtualNodes; $i++) {
            $virtualKey = $this->hash($nodeKey . ':' . $i);
            unset($this->ring[$virtualKey]);
        }
    }

    /**
     * Get the node for a given key
     */
    public function getNode(string $key): string
    {
        if (empty($this->ring)) {
            throw new \RuntimeException('No nodes available in hash ring');
        }

        $hash = $this->hash($key);
        
        // Find the first node with hash >= key hash
        foreach ($this->ring as $nodeHash => $nodeKey) {
            if ($nodeHash >= $hash) {
                return $nodeKey;
            }
        }

        // If no node found, return the first node (wrap around)
        return reset($this->ring);
    }

    /**
     * Get multiple nodes for a key (for replication)
     */
    public function getNodes(string $key, int $count = 1): array
    {
        if (empty($this->ring)) {
            throw new \RuntimeException('No nodes available in hash ring');
        }

        $hash = $this->hash($key);
        $nodes = [];
        $ringKeys = array_keys($this->ring);
        $ringSize = count($ringKeys);
        
        // Find starting position
        $startIndex = 0;
        for ($i = 0; $i < $ringSize; $i++) {
            if ($ringKeys[$i] >= $hash) {
                $startIndex = $i;
                break;
            }
        }

        // Collect unique nodes
        $collected = [];
        for ($i = 0; $i < $ringSize && count($collected) < $count; $i++) {
            $index = ($startIndex + $i) % $ringSize;
            $nodeKey = $this->ring[$ringKeys[$index]];
            
            if (!in_array($nodeKey, $collected)) {
                $collected[] = $nodeKey;
            }
        }

        return $collected;
    }

    /**
     * Hash function using MD5 for better distribution
     */
    private function hash(string $key): int
    {
        return (int) hexdec(substr(md5($key), 0, 8));
    }

    /**
     * Get all nodes
     */
    public function getAllNodes(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * Get ring statistics
     */
    public function getStats(): array
    {
        $nodeDistribution = [];
        foreach ($this->ring as $nodeKey) {
            $nodeDistribution[$nodeKey] = ($nodeDistribution[$nodeKey] ?? 0) + 1;
        }

        return [
            'total_nodes' => count($this->nodes),
            'virtual_nodes' => $this->virtualNodes,
            'ring_size' => count($this->ring),
            'distribution' => $nodeDistribution,
        ];
    }
}