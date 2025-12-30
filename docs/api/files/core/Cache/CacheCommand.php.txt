<?php

namespace HybridPHP\Core\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Cache management console command
 */
class CacheCommand extends Command
{
    private CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        parent::__construct();
        $this->cacheManager = $cacheManager;
    }

    protected function configure(): void
    {
        $this
            ->setName('cache')
            ->setDescription('Cache management commands')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (clear, stats, health)')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Cache store to use')
            ->addOption('key', 'k', InputOption::VALUE_OPTIONAL, 'Specific cache key')
            ->addOption('tags', 't', InputOption::VALUE_OPTIONAL, 'Cache tags (comma-separated)')
            ->setHelp('
Available actions:
  clear     - Clear cache (all or specific store)
  stats     - Show cache statistics
  health    - Check cache health
  get       - Get cache value by key
  delete    - Delete cache key
  warm      - Warm up cache
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $store = $input->getOption('store');
        $key = $input->getOption('key');
        $tags = $input->getOption('tags');

        try {
            switch ($action) {
                case 'clear':
                    return $this->clearCache($output, $store, $tags);
                
                case 'stats':
                    return $this->showStats($output, $store);
                
                case 'health':
                    return $this->checkHealth($output);
                
                case 'get':
                    return $this->getKey($output, $key, $store);
                
                case 'delete':
                    return $this->deleteKey($output, $key, $store);
                
                case 'warm':
                    return $this->warmCache($output, $store);
                
                default:
                    $output->writeln("<error>Unknown action: {$action}</error>");
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function clearCache(OutputInterface $output, ?string $store, ?string $tags): int
    {
        if ($tags) {
            $tagList = array_map('trim', explode(',', $tags));
            $this->cacheManager->invalidateByTags($tagList, $store)->await();
            $output->writeln("<info>Cache cleared for tags: " . implode(', ', $tagList) . "</info>");
        } elseif ($store) {
            $this->cacheManager->store($store)->clear()->await();
            $output->writeln("<info>Cache cleared for store: {$store}</info>");
        } else {
            $this->cacheManager->clearAll()->await();
            $output->writeln("<info>All caches cleared</info>");
        }

        return Command::SUCCESS;
    }

    private function showStats(OutputInterface $output, ?string $store): int
    {
        if ($store) {
            $stats = $this->cacheManager->getStats($store)->await();
            $this->displayStats($output, $store, $stats);
        } else {
            $config = $this->cacheManager->config['stores'] ?? [];
            foreach (array_keys($config) as $storeName) {
                try {
                    $stats = $this->cacheManager->getStats($storeName)->await();
                    $this->displayStats($output, $storeName, $stats);
                    $output->writeln('');
                } catch (\Throwable $e) {
                    $output->writeln("<error>Error getting stats for {$storeName}: {$e->getMessage()}</error>");
                }
            }
        }

        return Command::SUCCESS;
    }

    private function displayStats(OutputInterface $output, string $store, array $stats): void
    {
        $output->writeln("<info>Cache Stats for: {$store}</info>");
        
        $table = new Table($output);
        $table->setHeaders(['Metric', 'Value']);
        
        foreach ($stats as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }
            $table->addRow([$key, $value]);
        }
        
        $table->render();
    }

    private function checkHealth(OutputInterface $output): int
    {
        $output->writeln("<info>Checking cache health...</info>");
        
        $results = $this->cacheManager->healthCheck()->await();
        
        $table = new Table($output);
        $table->setHeaders(['Store', 'Status', 'Details']);
        
        foreach ($results as $store => $result) {
            $status = $result['status'];
            $details = $result['error'] ?? 'OK';
            
            if (isset($result['response_time'])) {
                $details = sprintf('Response time: %.2fms', $result['response_time'] * 1000);
            }
            
            $statusColor = $status === 'healthy' ? 'info' : 'error';
            $table->addRow([
                $store,
                "<{$statusColor}>{$status}</{$statusColor}>",
                $details
            ]);
        }
        
        $table->render();
        
        return Command::SUCCESS;
    }

    private function getKey(OutputInterface $output, ?string $key, ?string $store): int
    {
        if (!$key) {
            $output->writeln("<error>Key is required for get action</error>");
            return Command::FAILURE;
        }

        $cache = $this->cacheManager->store($store);
        $value = $cache->get($key)->await();
        
        if ($value === null) {
            $output->writeln("<comment>Key not found: {$key}</comment>");
        } else {
            $output->writeln("<info>Key: {$key}</info>");
            $output->writeln("Value: " . json_encode($value, JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }

    private function deleteKey(OutputInterface $output, ?string $key, ?string $store): int
    {
        if (!$key) {
            $output->writeln("<error>Key is required for delete action</error>");
            return Command::FAILURE;
        }

        $cache = $this->cacheManager->store($store);
        $deleted = $cache->delete($key)->await();
        
        if ($deleted) {
            $output->writeln("<info>Key deleted: {$key}</info>");
        } else {
            $output->writeln("<comment>Key not found: {$key}</comment>");
        }

        return Command::SUCCESS;
    }

    private function warmCache(OutputInterface $output, ?string $store): int
    {
        $output->writeln("<info>Cache warming not implemented yet</info>");
        $output->writeln("<comment>This would typically load frequently accessed data into cache</comment>");
        
        return Command::SUCCESS;
    }
}
