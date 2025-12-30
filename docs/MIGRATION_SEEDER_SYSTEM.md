# HybridPHP Migration & Seeder System

The HybridPHP Framework provides a comprehensive database migration and seeder system that supports multi-database environments, version management, and status tracking. This system is fully integrated with the CLI tools and follows async/await patterns for high performance.

## Features

- ✅ **Multi-Database Support**: Work with multiple database connections
- ✅ **Version Management**: Track migration versions and batches
- ✅ **Rollback Support**: Rollback migrations with step control
- ✅ **Status Tracking**: Monitor migration and seeder execution status
- ✅ **CLI Integration**: Full command-line interface support
- ✅ **Async/Await**: Non-blocking database operations
- ✅ **Transaction Safety**: All migrations run within transactions
- ✅ **Seeder System**: Populate databases with test or initial data
- ✅ **Template Generation**: Auto-generate migration and seeder files

## Migration System

### Creating Migrations

#### Basic Migration
```bash
php bin/hybrid make:migration create_users_table
```

#### Create Table Migration
```bash
php bin/hybrid make:migration create_users_table --create=users
```

#### Modify Table Migration
```bash
php bin/hybrid make:migration add_email_to_users --table=users
```

### Running Migrations

#### Run All Pending Migrations
```bash
php bin/hybrid migrate
```

#### Run Migrations on Specific Database
```bash
php bin/hybrid migrate --database=mysql_read
```

#### Force Migration in Production
```bash
php bin/hybrid migrate --force
```

#### Fresh Migrations (Drop All Tables and Re-run)
```bash
php bin/hybrid migrate --fresh
```

#### Refresh Migrations (Rollback All and Re-run)
```bash
php bin/hybrid migrate --refresh
```

### Rolling Back Migrations

#### Rollback Last Migration
```bash
php bin/hybrid migrate --rollback
```

#### Rollback Specific Number of Steps
```bash
php bin/hybrid migrate --rollback=3
```

#### Rollback All Migrations
```bash
php bin/hybrid migrate --rollback=all
# or
php bin/hybrid migrate --reset
```

### Migration Status

#### Show All Migration Status
```bash
php bin/hybrid migrate:status
```

#### Show Only Pending Migrations
```bash
php bin/hybrid migrate:status --pending
```

#### Show Only Executed Migrations
```bash
php bin/hybrid migrate:status --executed
```

### Migration File Structure

```php
<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Promise;

class CreateUsersTable extends AbstractMigration
{
    protected string $description = 'Create users table';

    public function up(DatabaseInterface $database): Future
    {
        return $this->createTable('users', [
            'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'password' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
        ], [
            'primary_key' => 'id',
            'unique' => ['users_email_unique' => 'email'],
            'indexes' => ['users_email_index' => 'email']
        ]);
    }

    public function down(DatabaseInterface $database): Future
    {
        return $this->dropTable('users');
    }
}
```

### Available Migration Methods

#### Table Operations
- `createTable(string $tableName, array $columns, array $options = [])`
- `dropTable(string $tableName)`

#### Column Operations
- `addColumn(string $tableName, string $columnName, array $definition)`
- `dropColumn(string $tableName, string $columnName)`

#### Index Operations
- `addIndex(string $tableName, string $indexName, array $columns)`
- `dropIndex(string $tableName, string $indexName)`

#### Raw SQL
- `execute(string $sql, array $params = [])`
- `query(string $sql, array $params = [])`

### Column Definition Options

```php
[
    'type' => 'VARCHAR',           // Column type (required)
    'length' => 255,               // Column length
    'unsigned' => true,            // For numeric types
    'nullable' => false,           // Allow NULL values
    'default' => 'default_value',  // Default value
    'auto_increment' => true,      // Auto increment (for integers)
    'comment' => 'Column comment', // Column comment
    'values' => ['val1', 'val2']   // For ENUM types
]
```

## Seeder System

### Creating Seeders

```bash
php bin/hybrid make:seeder UserSeeder
```

### Running Seeders

#### Run All Seeders
```bash
php bin/hybrid seed
```

#### Run Specific Seeder
```bash
php bin/hybrid seed --class=UserSeeder
```

#### Run Seeders on Specific Database
```bash
php bin/hybrid seed --database=mysql_write
```

#### Force Seeding in Production
```bash
php bin/hybrid seed --force
```

### Seeder File Structure

```php
<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Seeder\AbstractSeeder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Promise;
use function Amp\async;

class UserSeeder extends AbstractSeeder
{
    protected string $description = 'User seeder';

    public function run(DatabaseInterface $database): Future
    {
        return async(function () {
            // Insert single record
            $this->insert('users', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => password_hash('password', PASSWORD_DEFAULT),
            ])->await();

            // Insert multiple records
            $users = [];
            for ($i = 1; $i <= 10; $i++) {
                $users[] = [
                    'name' => $this->fake()->name(),
                    'email' => $this->fake()->email(),
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                ];
            }
            $this->insert('users', $users)->await();

            // Call other seeders
            $this->call(PostSeeder::class)->await();
        });
    }
}
```

### Available Seeder Methods

#### Data Operations
- `insert(string $table, array $data)`
- `update(string $table, array $data, array $where)`
- `delete(string $table, array $where)`
- `truncate(string $table)`

#### Utility Methods
- `call(string $seederClass)` - Call another seeder
- `fake()` - Get fake data generator

#### Raw SQL
- `execute(string $sql, array $params = [])`
- `query(string $sql, array $params = [])`

### Fake Data Generator

The seeder system includes a built-in fake data generator:

```php
$this->fake()->name()           // Random full name
$this->fake()->firstName()      // Random first name
$this->fake()->lastName()       // Random last name
$this->fake()->email()          // Random email address
$this->fake()->text(100)        // Random text (100 chars)
$this->fake()->number(1, 100)   // Random number between 1-100
$this->fake()->boolean()        // Random boolean
$this->fake()->date()           // Random date
```

## Database Configuration

The migration and seeder system supports multiple database connections:

```php
// config/database.php
return [
    'default' => 'mysql',
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'database' => env('DB_DATABASE', 'hybridphp'),
            // ... other config
        ],
        
        'mysql_read' => [
            'driver' => 'mysql',
            'host' => env('DB_READ_HOST', 'localhost'),
            'read' => true,
            // ... other config
        ],
        
        'mysql_write' => [
            'driver' => 'mysql',
            'host' => env('DB_WRITE_HOST', 'localhost'),
            'write' => true,
            // ... other config
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
```

## Directory Structure

```
database/
├── migrations/
│   ├── 2024_01_22_120000_create_users_table.php
│   ├── 2024_01_22_120001_create_posts_table.php
│   └── ...
└── seeds/
    ├── DatabaseSeeder.php
    ├── UserSeeder.php
    ├── PostSeeder.php
    └── ...
```

## Best Practices

### Migration Best Practices

1. **Always provide rollback logic** in the `down()` method
2. **Use transactions** for complex migrations (handled automatically)
3. **Test migrations** on development environment first
4. **Use descriptive names** for migration files
5. **Keep migrations small** and focused on single changes
6. **Never modify existing migrations** that have been run in production

### Seeder Best Practices

1. **Use DatabaseSeeder** as the main entry point
2. **Make seeders idempotent** (safe to run multiple times)
3. **Use fake data generators** for test data
4. **Organize seeders logically** (users before posts, etc.)
5. **Handle dependencies** between seeders properly

### Production Considerations

1. **Always backup** before running migrations in production
2. **Use `--force` flag** carefully in production
3. **Test rollback procedures** before deploying
4. **Monitor migration performance** for large datasets
5. **Consider maintenance windows** for schema changes

## Error Handling

The system provides comprehensive error handling:

- **Transaction rollback** on migration failures
- **Detailed error messages** with stack traces
- **Graceful failure recovery** 
- **Connection health checks**
- **Automatic retry mechanisms** for transient failures

## Performance Considerations

- **Async/await operations** prevent blocking
- **Connection pooling** for efficient resource usage
- **Batch operations** for large datasets
- **Index creation** during off-peak hours
- **Progress monitoring** for long-running operations

## Testing

Run the comprehensive test suite:

```bash
php test_migration_seeder_system.php
```

This test verifies:
- Migration system initialization
- Migration execution and rollback
- Seeder system functionality
- CLI command integration
- Multi-database support
- Error handling and recovery

## Troubleshooting

### Common Issues

1. **Connection timeouts**: Increase connection timeout in config
2. **Memory limits**: Use batch processing for large datasets
3. **Lock timeouts**: Avoid long-running migrations during peak hours
4. **Foreign key constraints**: Disable checks temporarily if needed

### Debug Mode

Enable verbose output for debugging:

```bash
php bin/hybrid migrate -v
php bin/hybrid seed -v
```

## Integration with HybridPHP Framework

The migration and seeder system is fully integrated with:

- **Dependency Injection Container**: Automatic service resolution
- **Configuration System**: Environment-based configuration
- **Logging System**: Comprehensive operation logging
- **Event System**: Migration/seeder lifecycle events
- **CLI System**: Rich command-line interface
- **Database Manager**: Multi-connection support with pooling

This system provides a robust foundation for database schema management and data seeding in HybridPHP applications.