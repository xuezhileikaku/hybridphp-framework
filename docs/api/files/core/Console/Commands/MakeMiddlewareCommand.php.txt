<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Make middleware command
 */
class MakeMiddlewareCommand extends Command
{
    protected static $defaultName = 'make:middleware';
    protected static $defaultDescription = 'Create a new middleware class';

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new middleware class')
            ->setHelp('This command allows you to create a new middleware class')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the middleware'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $name = $input->getArgument('name');
            $filename = $this->createMiddleware($name);
            
            $io->success("Middleware created: {$filename}");
            $io->text("Edit the middleware file at: app/Middleware/{$filename}");
            $io->note("Don't forget to register your middleware in the middleware configuration.");
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to create middleware: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function createMiddleware(string $name): string
    {
        $className = $this->studlyCase($name);
        if (!str_ends_with($className, 'Middleware')) {
            $className .= 'Middleware';
        }
        
        $filename = "{$className}.php";
        $filepath = 'app/Middleware/' . $filename;
        
        if (!is_dir('app/Middleware')) {
            mkdir('app/Middleware', 0755, true);
        }
        
        $template = $this->getMiddlewareTemplate($className);
        file_put_contents($filepath, $template);
        
        return $filename;
    }

    private function studlyCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    private function getMiddlewareTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Middleware;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Future;
use function Amp\async;

/**
 * {$className}
 */
class {$className} implements MiddlewareInterface
{
    /**
     * Process an incoming server request
     */
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): Future
    {
        return async(function () use (\$request, \$handler) {
            // Pre-processing logic here
            // You can modify the request or perform checks
            
            // Example: Add custom header
            // \$request = \$request->withHeader('X-Custom-Header', 'value');
            
            // Call the next middleware/handler
            \$response = \$handler->handle(\$request)->await();
            
            // Post-processing logic here
            // You can modify the response
            
            // Example: Add response header
            // \$response = \$response->withHeader('X-Processed-By', '{$className}');
            
            return \$response;
        });
    }
}
PHP;
    }
}