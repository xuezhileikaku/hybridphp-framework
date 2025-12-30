<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Make project scaffolding command
 */
class MakeProjectCommand extends Command
{
    protected static $defaultName = 'make:project';
    protected static $defaultDescription = 'Create a new HybridPHP project scaffolding';

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new HybridPHP project scaffolding')
            ->setHelp('This command creates a complete project structure with controllers, models, and configuration')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the project'
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Project type (api, web, full)',
                'full'
            )
            ->addOption(
                'auth',
                'a',
                InputOption::VALUE_NONE,
                'Include authentication scaffolding'
            )
            ->addOption(
                'database',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database type (mysql, postgresql)',
                'mysql'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $projectName = $input->getArgument('name');
            $projectType = $input->getOption('type');
            $includeAuth = $input->getOption('auth');
            $database = $input->getOption('database');
            
            $io->title("Creating HybridPHP Project: {$projectName}");
            
            // Validate project type
            if (!in_array($projectType, ['api', 'web', 'full'])) {
                $io->error('Invalid project type. Choose from: api, web, full');
                return Command::FAILURE;
            }
            
            // Create project structure
            $this->createProjectStructure($projectName, $projectType, $io);
            
            // Create basic controllers
            $this->createBasicControllers($projectType, $io);
            
            // Create models if needed
            if ($projectType !== 'api') {
                $this->createBasicModels($io);
            }
            
            // Create authentication scaffolding if requested
            if ($includeAuth) {
                $this->createAuthScaffolding($projectType, $database, $io);
            }
            
            // Create configuration files
            $this->createConfigFiles($projectName, $database, $io);
            
            // Create routes
            $this->createRoutes($projectType, $includeAuth, $io);
            
            // Create middleware
            $this->createMiddleware($projectType, $io);
            
            // Create database migrations
            $this->createDatabaseMigrations($includeAuth, $io);
            
            // Create tests
            $this->createTests($projectType, $io);
            
            // Display completion message
            $this->displayCompletionMessage($io, $projectName, $projectType);
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to create project: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function createProjectStructure(string $projectName, string $projectType, SymfonyStyle $io): void
    {
        $io->section('Creating project structure');
        
        $directories = [
            'app/Controllers',
            'app/Models',
            'app/Middleware',
            'app/Services',
            'config',
            'database/migrations',
            'database/seeds',
            'public',
            'resources/views',
            'routes',
            'storage/logs',
            'storage/cache',
            'storage/sessions',
            'tests/Unit',
            'tests/Feature'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $io->text("Created directory: {$dir}");
            }
        }
    }

    private function createBasicControllers(string $projectType, SymfonyStyle $io): void
    {
        $io->section('Creating basic controllers');
        
        // Home controller
        $this->createHomeController($projectType);
        $io->text('Created HomeController');
        
        if ($projectType === 'api' || $projectType === 'full') {
            // API controllers
            $this->createApiController();
            $io->text('Created ApiController');
        }
        
        if ($projectType === 'web' || $projectType === 'full') {
            // Web controllers
            $this->createWebController();
            $io->text('Created WebController');
        }
    }

    private function createBasicModels(SymfonyStyle $io): void
    {
        $io->section('Creating basic models');
        
        // User model (commonly needed)
        $this->createUserModel();
        $io->text('Created User model');
    }

    private function createAuthScaffolding(string $projectType, string $database, SymfonyStyle $io): void
    {
        $io->section('Creating authentication scaffolding');
        
        // Auth controller
        $this->createAuthController($projectType);
        $io->text('Created AuthController');
        
        // Auth middleware
        $this->createAuthMiddleware();
        $io->text('Created AuthMiddleware');
        
        // Auth service
        $this->createAuthService();
        $io->text('Created AuthService');
    }

    private function createConfigFiles(string $projectName, string $database, SymfonyStyle $io): void
    {
        $io->section('Creating configuration files');
        
        // Main config
        $this->createMainConfig($projectName);
        $io->text('Created main.php config');
        
        // Database config
        $this->createDatabaseConfig($database);
        $io->text('Created database.php config');
        
        // Auth config
        $this->createAuthConfig();
        $io->text('Created auth.php config');
        
        // Environment file
        $this->createEnvironmentFile($projectName, $database);
        $io->text('Created .env file');
    }

    private function createRoutes(string $projectType, bool $includeAuth, SymfonyStyle $io): void
    {
        $io->section('Creating routes');
        
        if ($projectType === 'api' || $projectType === 'full') {
            $this->createApiRoutes($includeAuth);
            $io->text('Created API routes');
        }
        
        if ($projectType === 'web' || $projectType === 'full') {
            $this->createWebRoutes($includeAuth);
            $io->text('Created web routes');
        }
    }

    private function createMiddleware(string $projectType, SymfonyStyle $io): void
    {
        $io->section('Creating middleware');
        
        // CORS middleware for API
        if ($projectType === 'api' || $projectType === 'full') {
            $this->createCorsMiddleware();
            $io->text('Created CORS middleware');
        }
        
        // Rate limiting middleware
        $this->createRateLimitMiddleware();
        $io->text('Created rate limit middleware');
    }

    private function createDatabaseMigrations(bool $includeAuth, SymfonyStyle $io): void
    {
        $io->section('Creating database migrations');
        
        if ($includeAuth) {
            $this->createUsersMigration();
            $io->text('Created users table migration');
        }
    }

    private function createTests(string $projectType, SymfonyStyle $io): void
    {
        $io->section('Creating test files');
        
        $this->createBasicTests($projectType);
        $io->text('Created basic test files');
    }

    // Implementation methods for each scaffolding component
    private function createHomeController(string $projectType): void
    {
        $template = $projectType === 'api' ? $this->getApiHomeControllerTemplate() : $this->getWebHomeControllerTemplate();
        file_put_contents('app/Controllers/HomeController.php', $template);
    }

    private function createApiController(): void
    {
        $template = $this->getApiControllerTemplate();
        file_put_contents('app/Controllers/ApiController.php', $template);
    }

    private function createWebController(): void
    {
        $template = $this->getWebControllerTemplate();
        file_put_contents('app/Controllers/WebController.php', $template);
    }

    private function createUserModel(): void
    {
        $template = $this->getUserModelTemplate();
        file_put_contents('app/Models/User.php', $template);
    }

    private function createAuthController(string $projectType): void
    {
        $template = $this->getAuthControllerTemplate($projectType);
        file_put_contents('app/Controllers/AuthController.php', $template);
    }

    private function createAuthMiddleware(): void
    {
        $template = $this->getAuthMiddlewareTemplate();
        file_put_contents('app/Middleware/AuthMiddleware.php', $template);
    }

    private function createAuthService(): void
    {
        $template = $this->getAuthServiceTemplate();
        file_put_contents('app/Services/AuthService.php', $template);
    }

    private function createMainConfig(string $projectName): void
    {
        $template = $this->getMainConfigTemplate($projectName);
        file_put_contents('config/main.php', $template);
    }

    private function createDatabaseConfig(string $database): void
    {
        $template = $this->getDatabaseConfigTemplate($database);
        file_put_contents('config/database.php', $template);
    }

    private function createAuthConfig(): void
    {
        $template = $this->getAuthConfigTemplate();
        file_put_contents('config/auth.php', $template);
    }

    private function createEnvironmentFile(string $projectName, string $database): void
    {
        $template = $this->getEnvironmentTemplate($projectName, $database);
        file_put_contents('.env', $template);
    }

    private function createApiRoutes(bool $includeAuth): void
    {
        $template = $this->getApiRoutesTemplate($includeAuth);
        file_put_contents('routes/api.php', $template);
    }

    private function createWebRoutes(bool $includeAuth): void
    {
        $template = $this->getWebRoutesTemplate($includeAuth);
        file_put_contents('routes/web.php', $template);
    }

    private function createCorsMiddleware(): void
    {
        $template = $this->getCorsMiddlewareTemplate();
        file_put_contents('app/Middleware/CorsMiddleware.php', $template);
    }

    private function createRateLimitMiddleware(): void
    {
        $template = $this->getRateLimitMiddlewareTemplate();
        file_put_contents('app/Middleware/RateLimitMiddleware.php', $template);
    }

    private function createUsersMigration(): void
    {
        $timestamp = date('Y_m_d_His');
        $template = $this->getUsersMigrationTemplate();
        file_put_contents("database/migrations/{$timestamp}_create_users_table.php", $template);
    }

    private function createBasicTests(string $projectType): void
    {
        $template = $this->getBasicTestTemplate($projectType);
        file_put_contents('tests/Feature/BasicTest.php', $template);
    }

    private function displayCompletionMessage(SymfonyStyle $io, string $projectName, string $projectType): void
    {
        $io->success("Project '{$projectName}' created successfully!");
        
        $io->section('Next Steps:');
        $io->listing([
            'Configure your database settings in .env file',
            'Run migrations: php bin/hybrid migrate',
            'Start the server: php bin/hybrid server start',
            'Visit http://localhost:8080 to see your application'
        ]);
        
        $io->note("Project type: {$projectType}");
        $io->text('Happy coding with HybridPHP!');
    }

    // Template methods (simplified versions)
    private function getApiHomeControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

class HomeController
{
    public function index(Request $request): Promise
    {
        return async(function () {
            return Response::json([
                'message' => 'Welcome to HybridPHP API',
                'version' => '1.0.0',
                'timestamp' => date('c')
            ]);
        });
    }
}
PHP;
    }

    private function getWebHomeControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

class HomeController
{
    public function index(Request $request): Promise
    {
        return async(function () {
            return Response::html('<h1>Welcome to HybridPHP</h1><p>Your application is ready!</p>');
        });
    }
}
PHP;
    }

    private function getApiControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

abstract class ApiController
{
    protected function jsonResponse(array $data, int $status = 200): Promise
    {
        return async(function () use ($data, $status) {
            return Response::json($data, $status);
        });
    }

    protected function errorResponse(string $message, int $status = 400): Promise
    {
        return async(function () use ($message, $status) {
            return Response::json(['error' => $message], $status);
        });
    }
}
PHP;
    }

    private function getWebControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

abstract class WebController
{
    protected function view(string $template, array $data = []): Promise
    {
        return async(function () use ($template, $data) {
            // TODO: Implement view rendering
            return Response::html("<h1>{$template}</h1>");
        });
    }

    protected function redirect(string $url, int $status = 302): Promise
    {
        return async(function () use ($url, $status) {
            return Response::redirect($url, $status);
        });
    }
}
PHP;
    }

    private function getUserModelTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\Model\ActiveRecord;

class User extends ActiveRecord
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'name',
        'email',
        'password'
    ];
    
    protected array $hidden = [
        'password'
    ];
    
    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    public bool $timestamps = true;
}
PHP;
    }

    private function getAuthControllerTemplate(string $projectType): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use App\Services\AuthService;
use Amp\Future;
use function Amp\async;

class AuthController
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement login logic
            return Response::json(['message' => 'Login endpoint']);
        });
    }

    public function register(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement registration logic
            return Response::json(['message' => 'Register endpoint']);
        });
    }

    public function logout(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement logout logic
            return Response::json(['message' => 'Logout successful']);
        });
    }
}
PHP;
    }

    private function getAuthMiddlewareTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Middleware;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Future;
use function Amp\async;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Future
    {
        return async(function () use ($request, $handler) {
            // TODO: Implement authentication check
            return $handler->handle($request)->await();
        });
    }
}
PHP;
    }

    private function getAuthServiceTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Amp\Future;
use function Amp\async;

class AuthService
{
    public function authenticate(string $email, string $password): Future
    {
        return async(function () use ($email, $password) {
            // TODO: Implement authentication logic
            return null;
        });
    }

    public function register(array $userData): Future
    {
        return async(function () use ($userData) {
            // TODO: Implement user registration
            return null;
        });
    }
}
PHP;
    }

    private function getMainConfigTemplate(string $projectName): string
    {
        return <<<PHP
<?php

return [
    'app' => [
        'name' => '{$projectName}',
        'version' => '1.0.0',
        'debug' => env('APP_DEBUG', false),
        'timezone' => 'UTC',
    ],
    
    'server' => [
        'host' => env('SERVER_HOST', '127.0.0.1'),
        'port' => env('SERVER_PORT', 8080),
        'workers' => env('SERVER_WORKERS', 4),
    ],
    
    'middleware' => [
        'global' => [
            // Add global middleware here
        ],
    ],
];
PHP;
    }

    private function getDatabaseConfigTemplate(string $database): string
    {
        return <<<PHP
<?php

return [
    'default' => env('DB_CONNECTION', '{$database}'),
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        
        'postgresql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
        ],
    ],
    
    'migrations' => [
        'table' => 'migrations',
        'path' => 'database/migrations',
    ],
    
    'seeds' => [
        'path' => 'database/seeds',
    ],
];
PHP;
    }

    private function getAuthConfigTemplate(): string
    {
        return <<<'PHP'
<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],
    
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],
    
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],
    
    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'ttl' => 60, // minutes
        'refresh_ttl' => 20160, // minutes
    ],
];
PHP;
    }

    private function getEnvironmentTemplate(string $projectName, string $database): string
    {
        return <<<ENV
APP_NAME={$projectName}
APP_DEBUG=true
APP_ENV=local

SERVER_HOST=127.0.0.1
SERVER_PORT=8080
SERVER_WORKERS=4

DB_CONNECTION={$database}
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hybridphp
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

JWT_SECRET=your-secret-key-here

LOG_LEVEL=debug
ENV;
    }

    private function getApiRoutesTemplate(bool $includeAuth): string
    {
        $authRoutes = $includeAuth ? "
    // Authentication routes
    \$router->post('/auth/login', [AuthController::class, 'login']);
    \$router->post('/auth/register', [AuthController::class, 'register']);
    \$router->post('/auth/logout', [AuthController::class, 'logout']);" : '';

        return <<<PHP
<?php

use HybridPHP\Core\Routing\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;

return function (Router \$router) {
    // API routes
    \$router->get('/', [HomeController::class, 'index']);
    \$router->get('/health', function () {
        return ['status' => 'ok', 'timestamp' => time()];
    });{$authRoutes}
};
PHP;
    }

    private function getWebRoutesTemplate(bool $includeAuth): string
    {
        $authRoutes = $includeAuth ? "
    // Authentication routes
    \$router->get('/login', [AuthController::class, 'showLogin']);
    \$router->post('/login', [AuthController::class, 'login']);
    \$router->get('/register', [AuthController::class, 'showRegister']);
    \$router->post('/register', [AuthController::class, 'register']);
    \$router->post('/logout', [AuthController::class, 'logout']);" : '';

        return <<<PHP
<?php

use HybridPHP\Core\Routing\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;

return function (Router \$router) {
    // Web routes
    \$router->get('/', [HomeController::class, 'index']);{$authRoutes}
};
PHP;
    }

    private function getCorsMiddlewareTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Middleware;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Future;
use function Amp\async;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Future
    {
        return async(function () use ($request, $handler) {
            $response = $handler->handle($request)->await();
            
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        });
    }
}
PHP;
    }

    private function getRateLimitMiddlewareTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Middleware;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Future;
use function Amp\async;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Future
    {
        return async(function () use ($request, $handler) {
            // TODO: Implement rate limiting logic
            return $handler->handle($request)->await();
        });
    }
}
PHP;
    }

    private function getUsersMigrationTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;

class CreateUsersTable extends AbstractMigration
{
    protected string $description = 'Create users table';

    public function up(DatabaseInterface $database): Promise
    {
        return $this->createTable('users', [
            'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255],
            'email' => ['type' => 'VARCHAR', 'length' => 255, 'unique' => true],
            'password' => ['type' => 'VARCHAR', 'length' => 255],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP', 'nullable' => true],
        ], [
            'primary_key' => 'id'
        ]);
    }

    public function down(DatabaseInterface $database): Promise
    {
        return $this->dropTable('users');
    }
}
PHP;
    }

    private function getBasicTestTemplate(string $projectType): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class BasicTest extends TestCase
{
    public function testBasicAssertions(): void
    {
        \$this->assertTrue(true);
    }
    
    public function testApplicationExists(): void
    {
        \$this->assertFileExists(__DIR__ . '/../../core/Application.php');
    }
}
PHP;
    }
}