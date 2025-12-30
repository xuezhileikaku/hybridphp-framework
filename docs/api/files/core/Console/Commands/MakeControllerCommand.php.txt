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
 * Make controller command
 */
class MakeControllerCommand extends Command
{
    protected static $defaultName = 'make:controller';
    protected static $defaultDescription = 'Create a new controller class';

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new controller class')
            ->setHelp('This command allows you to create a new controller class')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the controller'
            )
            ->addOption(
                'resource',
                'r',
                InputOption::VALUE_NONE,
                'Generate a resource controller with CRUD methods'
            )
            ->addOption(
                'api',
                null,
                InputOption::VALUE_NONE,
                'Generate an API controller'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $name = $input->getArgument('name');
            $isResource = $input->getOption('resource');
            $isApi = $input->getOption('api');
            
            $filename = $this->createController($name, $isResource, $isApi);
            
            $io->success("Controller created: {$filename}");
            $io->text("Edit the controller file at: app/Controllers/{$filename}");
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to create controller: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function createController(string $name, bool $isResource, bool $isApi): string
    {
        $className = $this->studlyCase($name);
        if (!str_ends_with($className, 'Controller')) {
            $className .= 'Controller';
        }
        
        $filename = "{$className}.php";
        $filepath = 'app/Controllers/' . $filename;
        
        if (!is_dir('app/Controllers')) {
            mkdir('app/Controllers', 0755, true);
        }
        
        if ($isResource) {
            $template = $isApi ? $this->getApiResourceTemplate($className) : $this->getResourceTemplate($className);
        } else {
            $template = $this->getBasicTemplate($className);
        }
        
        file_put_contents($filepath, $template);
        
        return $filename;
    }

    private function studlyCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    private function getBasicTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

/**
 * {$className}
 */
class {$className}
{
    /**
     * Handle the request
     */
    public function index(Request \$request): Future
    {
        return async(function () use (\$request) {
            return Response::json([
                'message' => 'Hello from {$className}!'
            ]);
        });
    }
}
PHP;
    }

    private function getResourceTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

/**
 * {$className} - Resource Controller
 */
class {$className}
{
    /**
     * Display a listing of the resource
     */
    public function index(Request \$request): Future
    {
        return async(function () use (\$request) {
            // TODO: Implement index logic
            return Response::json([
                'data' => [],
                'message' => 'Resource listing'
            ]);
        });
    }

    /**
     * Show the form for creating a new resource
     */
    public function create(Request \$request): Future
    {
        return async(function () use (\$request) {
            // TODO: Implement create form logic
            return Response::json([
                'message' => 'Create form'
            ]);
        });
    }

    /**
     * Store a newly created resource
     */
    public function store(Request \$request): Future
    {
        return async(function () use (\$request) {
            // TODO: Implement store logic
            return Response::json([
                'message' => 'Resource created'
            ], 201);
        });
    }

    /**
     * Display the specified resource
     */
    public function show(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Implement show logic
            return Response::json([
                'id' => \$id,
                'message' => 'Resource details'
            ]);
        });
    }

    /**
     * Show the form for editing the specified resource
     */
    public function edit(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Implement edit form logic
            return Response::json([
                'id' => \$id,
                'message' => 'Edit form'
            ]);
        });
    }

    /**
     * Update the specified resource
     */
    public function update(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Implement update logic
            return Response::json([
                'id' => \$id,
                'message' => 'Resource updated'
            ]);
        });
    }

    /**
     * Remove the specified resource
     */
    public function destroy(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Implement destroy logic
            return Response::json([
                'id' => \$id,
                'message' => 'Resource deleted'
            ]);
        });
    }
}
PHP;
    }

    private function getApiResourceTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

/**
 * {$className} - API Resource Controller
 */
class {$className}
{
    /**
     * Display a listing of the resource
     */
    public function index(Request \$request): Future
    {
        return async(function () use (\$request) {
            // TODO: Implement index logic
            return Response::json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'per_page' => 15
                ]
            ]);
        });
    }

    /**
     * Store a newly created resource
     */
    public function store(Request \$request): Future
    {
        return async(function () use (\$request) {
            // TODO: Validate input and create resource
            return Response::json([
                'data' => [],
                'message' => 'Resource created successfully'
            ], 201);
        });
    }

    /**
     * Display the specified resource
     */
    public function show(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Find and return resource
            return Response::json([
                'data' => [
                    'id' => \$id
                ]
            ]);
        });
    }

    /**
     * Update the specified resource
     */
    public function update(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Validate input and update resource
            return Response::json([
                'data' => [
                    'id' => \$id
                ],
                'message' => 'Resource updated successfully'
            ]);
        });
    }

    /**
     * Remove the specified resource
     */
    public function destroy(Request \$request, string \$id): Future
    {
        return async(function () use (\$request, \$id) {
            // TODO: Delete resource
            return Response::json([
                'message' => 'Resource deleted successfully'
            ]);
        });
    }
}
PHP;
    }
}