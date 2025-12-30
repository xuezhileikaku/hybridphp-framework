<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Seeder;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\async;

/**
 * Seeder manager for handling database seeders
 */
class SeederManager
{
    private DatabaseInterface $database;
    private array $config;
    private LoggerInterface $logger;
    private string $seedersPath;

    public function __construct(
        DatabaseInterface $database,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->database = $database;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->seedersPath = $config['path'] ?? 'database/seeds';
    }

    /**
     * Run all seeders
     */
    public function runAll(): Future
    {
        return async(function () {
            $seederFiles = $this->getAllSeederFiles();
            $executedSeeders = [];
            
            foreach ($seederFiles as $seederFile) {
                try {
                    $seederClass = $this->getClassNameFromFilename($seederFile);
                    $this->runSeederClass($seederClass)->await();
                    $executedSeeders[] = $seederClass;
                } catch (\Throwable $e) {
                    $this->logger->error("Seeder failed: {$seederFile}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }
            
            return $executedSeeders;
        });
    }

    /**
     * Run specific seeder
     */
    public function runSeeder(string $seederClass): Future
    {
        return async(function () use ($seederClass) {
            $this->runSeederClass($seederClass)->await();
            return $seederClass;
        });
    }

    /**
     * Create a new seeder file
     */
    public function createSeeder(string $name): string
    {
        // Don't add 'Seeder' suffix if it already exists
        $className = $this->studlyCase($name);
        if (!str_ends_with($className, 'Seeder')) {
            $className .= 'Seeder';
        }
        $filename = "{$className}.php";
        $filepath = $this->seedersPath . '/' . $filename;
        
        $template = $this->getSeederTemplate($className, $name);
        
        if (!is_dir($this->seedersPath)) {
            mkdir($this->seedersPath, 0755, true);
        }
        
        file_put_contents($filepath, $template);
        
        return $filename;
    }

    /**
     * Run seeder class
     */
    private function runSeederClass(string $seederClass): Future
    {
        return async(function () use ($seederClass) {
            $seeder = $this->loadSeeder($seederClass);
            $seeder->setDatabase($this->database);
            
            $this->logger->info("Running seeder: {$seederClass}");
            
            $this->database->transaction(function () use ($seeder) {
                return $seeder->run($this->database);
            })->await();
            
            $this->logger->info("Seeder completed: {$seederClass}");
        });
    }

    /**
     * Get all seeder files
     */
    private function getAllSeederFiles(): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }
        
        $files = scandir($this->seedersPath);
        $seederFiles = [];
        
        foreach ($files as $file) {
            if (preg_match('/^.*Seeder\.php$/', $file) && $file !== 'DatabaseSeeder.php') {
                $seederFiles[] = $file;
            }
        }
        
        // Check for DatabaseSeeder.php and put it first if it exists
        if (file_exists($this->seedersPath . '/DatabaseSeeder.php')) {
            array_unshift($seederFiles, 'DatabaseSeeder.php');
        }
        
        return $seederFiles;
    }

    /**
     * Load seeder instance
     */
    private function loadSeeder(string $seederClass): SeederInterface
    {
        // Try to find the file
        $filename = $seederClass . '.php';
        $filepath = $this->seedersPath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Seeder file not found: {$filepath}");
        }
        
        require_once $filepath;
        
        if (!class_exists($seederClass)) {
            throw new \RuntimeException("Seeder class not found: {$seederClass}");
        }
        
        $seeder = new $seederClass();
        
        if (!$seeder instanceof SeederInterface) {
            throw new \RuntimeException("Seeder must implement SeederInterface: {$seederClass}");
        }
        
        return $seeder;
    }

    /**
     * Get class name from filename
     */
    private function getClassNameFromFilename(string $filename): string
    {
        return str_replace('.php', '', $filename);
    }

    /**
     * Convert string to StudlyCase
     */
    private function studlyCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    /**
     * Get seeder template
     */
    private function getSeederTemplate(string $className, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Seeder\AbstractSeeder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use function Amp\async;

/**
 * Seeder: {$name}
 */
class {$className} extends AbstractSeeder
{
    protected string \$description = '{$name} seeder';

    /**
     * Run the seeder
     */
    public function run(DatabaseInterface \$database): Future
    {
        return async(function () {
            // TODO: Implement seeder logic
            // Example:
            // \$this->insert('users', [
            //     [
            //         'name' => \$this->fake()->name(),
            //         'email' => \$this->fake()->email(),
            //         'created_at' => \$this->fake()->date(),
            //     ],
            //     [
            //         'name' => \$this->fake()->name(),
            //         'email' => \$this->fake()->email(),
            //         'created_at' => \$this->fake()->date(),
            //     ]
            // ])->await();
            
            // Or call other seeders:
            // \$this->call(UserSeeder::class)->await();
        });
    }
}
PHP;
    }
}