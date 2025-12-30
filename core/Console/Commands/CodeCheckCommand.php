<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Code quality check command
 */
class CodeCheckCommand extends Command
{
    protected static $defaultName = 'code:check';
    protected static $defaultDescription = 'Run code quality checks and analysis';

    protected function configure(): void
    {
        $this
            ->setDescription('Run code quality checks and analysis')
            ->setHelp('This command runs various code quality tools including PHPStan, PHP_CodeSniffer, and custom checks')
            ->addArgument(
                'tool',
                InputArgument::OPTIONAL,
                'Specific tool to run (phpstan, phpcs, phpcbf, all)',
                'all'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to analyze',
                'app'
            )
            ->addOption(
                'fix',
                null,
                InputOption::VALUE_NONE,
                'Automatically fix code style issues'
            )
            ->addOption(
                'level',
                'l',
                InputOption::VALUE_REQUIRED,
                'PHPStan analysis level (0-9)',
                '5'
            )
            ->addOption(
                'standard',
                's',
                InputOption::VALUE_REQUIRED,
                'Coding standard for PHPCS',
                'PSR12'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tool = $input->getArgument('tool');
        $path = $input->getOption('path');
        $fix = $input->getOption('fix');
        
        $io->title('HybridPHP Code Quality Check');
        
        try {
            $results = [];
            
            switch ($tool) {
                case 'phpstan':
                    $results['phpstan'] = $this->runPhpStan($input, $io);
                    break;
                case 'phpcs':
                    $results['phpcs'] = $this->runPhpCs($input, $io);
                    break;
                case 'phpcbf':
                    $results['phpcbf'] = $this->runPhpCbf($input, $io);
                    break;
                case 'all':
                default:
                    $results['phpstan'] = $this->runPhpStan($input, $io);
                    $results['phpcs'] = $this->runPhpCs($input, $io);
                    if ($fix) {
                        $results['phpcbf'] = $this->runPhpCbf($input, $io);
                    }
                    $results['custom'] = $this->runCustomChecks($input, $io);
                    break;
            }
            
            // Display summary
            $this->displaySummary($io, $results);
            
            // Return appropriate exit code
            $hasErrors = false;
            foreach ($results as $result) {
                if ($result !== Command::SUCCESS) {
                    $hasErrors = true;
                    break;
                }
            }
            
            return $hasErrors ? Command::FAILURE : Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Code check failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function runPhpStan(InputInterface $input, SymfonyStyle $io): int
    {
        $io->section('Running PHPStan Static Analysis');
        
        $path = $input->getOption('path');
        $level = $input->getOption('level');
        
        // Check if PHPStan is available
        if (!$this->isToolAvailable('phpstan')) {
            $io->warning('PHPStan is not installed. Install it with: composer require --dev phpstan/phpstan');
            return Command::SUCCESS;
        }
        
        // Create PHPStan config if it doesn't exist
        $this->createPhpStanConfig();
        
        $command = "vendor/bin/phpstan analyse {$path} --level={$level} --no-progress";
        
        $io->text("Running: {$command}");
        
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $io->success('PHPStan analysis passed');
        } else {
            $io->error('PHPStan found issues:');
            foreach ($output as $line) {
                $io->text($line);
            }
        }
        
        return $returnCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runPhpCs(InputInterface $input, SymfonyStyle $io): int
    {
        $io->section('Running PHP_CodeSniffer');
        
        $path = $input->getOption('path');
        $standard = $input->getOption('standard');
        
        // Check if PHP_CodeSniffer is available
        if (!$this->isToolAvailable('phpcs')) {
            $io->warning('PHP_CodeSniffer is not installed. Install it with: composer require --dev squizlabs/php_codesniffer');
            return Command::SUCCESS;
        }
        
        $command = "vendor/bin/phpcs {$path} --standard={$standard} --colors";
        
        $io->text("Running: {$command}");
        
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $io->success('Code style check passed');
        } else {
            $io->error('Code style issues found:');
            foreach ($output as $line) {
                $io->text($line);
            }
        }
        
        return $returnCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runPhpCbf(InputInterface $input, SymfonyStyle $io): int
    {
        $io->section('Running PHP Code Beautifier and Fixer');
        
        $path = $input->getOption('path');
        $standard = $input->getOption('standard');
        
        // Check if PHP_CodeSniffer is available
        if (!$this->isToolAvailable('phpcbf')) {
            $io->warning('PHP Code Beautifier is not installed. Install it with: composer require --dev squizlabs/php_codesniffer');
            return Command::SUCCESS;
        }
        
        $command = "vendor/bin/phpcbf {$path} --standard={$standard}";
        
        $io->text("Running: {$command}");
        
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        if (empty($output)) {
            $io->success('No fixable issues found');
        } else {
            $io->success('Code style issues fixed:');
            foreach ($output as $line) {
                $io->text($line);
            }
        }
        
        return Command::SUCCESS;
    }

    private function runCustomChecks(InputInterface $input, SymfonyStyle $io): int
    {
        $io->section('Running Custom Framework Checks');
        
        $path = $input->getOption('path');
        $issues = [];
        
        // Check for synchronous code patterns
        $issues = array_merge($issues, $this->checkForSynchronousPatterns($path));
        
        // Check for proper async/await usage
        $issues = array_merge($issues, $this->checkAsyncAwaitUsage($path));
        
        // Check for proper PSR compliance
        $issues = array_merge($issues, $this->checkPsrCompliance($path));
        
        // Check for security issues
        $issues = array_merge($issues, $this->checkSecurityIssues($path));
        
        if (empty($issues)) {
            $io->success('All custom checks passed');
            return Command::SUCCESS;
        } else {
            $io->error('Custom checks found issues:');
            foreach ($issues as $issue) {
                $io->text("â€?{$issue}");
            }
            return Command::FAILURE;
        }
    }

    private function isToolAvailable(string $tool): bool
    {
        return file_exists("vendor/bin/{$tool}");
    }

    private function createPhpStanConfig(): void
    {
        $configPath = 'phpstan.neon';
        
        if (file_exists($configPath)) {
            return;
        }
        
        $config = <<<NEON
parameters:
    level: 5
    paths:
        - app
        - core
    excludePaths:
        - vendor
    ignoreErrors:
        - '#Call to an undefined method.*Future#'
    checkMissingIterableValueType: false
NEON;
        
        file_put_contents($configPath, $config);
    }

    private function checkForSynchronousPatterns(string $path): array
    {
        $issues = [];
        $files = $this->getPhpFiles($path);
        
        $synchronousPatterns = [
            'file_get_contents(' => 'Use async file operations instead',
            'curl_exec(' => 'Use amphp/http-client for async HTTP requests',
            'mysqli_query(' => 'Use amphp/mysql for async database operations',
            'sleep(' => 'Use Amp\delay() for async delays',
            'usleep(' => 'Use Amp\delay() for async delays',
        ];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            foreach ($synchronousPatterns as $pattern => $message) {
                if (strpos($content, $pattern) !== false) {
                    $issues[] = "{$file}: {$message}";
                }
            }
        }
        
        return $issues;
    }

    private function checkAsyncAwaitUsage(string $path): array
    {
        $issues = [];
        $files = $this->getPhpFiles($path);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            // Check for yield usage (should be replaced with ->await() in AMPHP v3)
            if (preg_match('/yield\s+\$/', $content)) {
                $issues[] = "{$file}: Found 'yield' - should use ->await() in AMPHP v3";
            }
            
            // Check for Promise type hints (should be Future in AMPHP v3)
            if (preg_match('/:\s*Promise\b/', $content)) {
                $issues[] = "{$file}: Found 'Promise' type hint - should use 'Future' in AMPHP v3";
            }
            
            // Check for Promise imports (should be Future in AMPHP v3)
            if (preg_match('/use Amp\\\\Promise;/', $content)) {
                $issues[] = "{$file}: Found 'use Amp\\Promise' - should use 'use Amp\\Future' in AMPHP v3";
            }
        }
        
        return $issues;
    }

    private function checkPsrCompliance(string $path): array
    {
        $issues = [];
        $files = $this->getPhpFiles($path);
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            // Check for proper namespace declaration
            if (!preg_match('/^<\?php\s*\n\s*declare\(strict_types=1\);/', $content)) {
                $issues[] = "{$file}: Missing strict_types declaration";
            }
            
            // Check for proper class naming
            $className = basename($file, '.php');
            if (!preg_match('/class\s+' . preg_quote($className) . '\s*/', $content)) {
                $issues[] = "{$file}: Class name doesn't match filename";
            }
        }
        
        return $issues;
    }

    private function checkSecurityIssues(string $path): array
    {
        $issues = [];
        $files = $this->getPhpFiles($path);
        
        $securityPatterns = [
            'eval(' => 'Avoid using eval() - security risk',
            'exec(' => 'Be careful with exec() - validate input',
            'system(' => 'Be careful with system() - validate input',
            '\$_GET\[' => 'Validate and sanitize GET input',
            '\$_POST\[' => 'Validate and sanitize POST input',
            'md5(' => 'Consider using stronger hashing algorithms',
            'sha1(' => 'Consider using stronger hashing algorithms',
        ];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            foreach ($securityPatterns as $pattern => $message) {
                if (preg_match('/' . $pattern . '/', $content)) {
                    $issues[] = "{$file}: {$message}";
                }
            }
        }
        
        return $issues;
    }

    private function getPhpFiles(string $path): array
    {
        $files = [];
        
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            return [$path];
        }
        
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        
        return $files;
    }

    private function displaySummary(SymfonyStyle $io, array $results): void
    {
        $io->section('Summary');
        
        $passed = 0;
        $failed = 0;
        
        foreach ($results as $tool => $result) {
            if ($result === Command::SUCCESS) {
                $io->text("âœ?{$tool}: PASSED");
                $passed++;
            } else {
                $io->text("â?{$tool}: FAILED");
                $failed++;
            }
        }
        
        $io->newLine();
        $io->text("Total checks: " . count($results));
        $io->text("Passed: {$passed}");
        $io->text("Failed: {$failed}");
        
        if ($failed === 0) {
            $io->success('All code quality checks passed!');
        } else {
            $io->error("Code quality issues found. Please fix the issues above.");
        }
    }
}