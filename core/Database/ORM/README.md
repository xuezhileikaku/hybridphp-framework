# HybridPHP ORM System

The HybridPHP ORM (Object-Relational Mapping) system provides both **ActiveRecord** and **DataMapper** patterns for database interaction, following Yii2-style APIs while maintaining full asynchronous operation.

## Features

- **Dual Pattern Support**: Both ActiveRecord and DataMapper patterns
- **Fully Asynchronous**: All database operations are non-blocking
- **Yii2-Style API**: Familiar and intuitive interface
- **Relationship Mapping**: Support for one-to-one, one-to-many, and many-to-many relationships
- **Data Validation**: Built-in validation system with extensible validators
- **Type Conversion**: Automatic type conversion between PHP and database formats
- **Transaction Support**: Full transaction support with rollback capabilities
- **Query Builder Integration**: Chainable query building with the underlying QueryBuilder

## ActiveRecord Pattern

### Basic Usage

```php
use App\Models\User;
use Amp\Loop;

Loop::run(function () {
    // Create a new user
    $user = new User([
        'username' => 'john_doe',
        'email' => 'john@example.com',
        'password' => 'secret123'
    ]);
    
    // Save to database
    $saved = yield $user->save();
    if ($saved) {
        echo "User created with ID: " . $user->id;
    }
    
    // Find user by primary key
    $foundUser = yield User::findByPk(1);
    
    // Find users by criteria
    $activeUsers = yield User::find()
        ->where(['status' => 1])
        ->orderBy(['created_at' => 'DESC'])
        ->all();
    
    // Update user
    $user->email = 'newemail@example.com';
    yield $user->save();
    
    // Delete user
    yield $user->delete();
});
```

### Model Definition

```php
<?php

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;

class User extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'users';
    }
    
    public function rules(): array
    {
        return [
            [['username', 'email'], 'required'],
            [['username'], 'string', ['min' => 3, 'max' => 50]],
            [['email'], 'email'],
            [['username', 'email'], 'unique'],
        ];
    }
    
    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'email' => 'Email Address',
        ];
    }
}
```

### Relationships

```php
// One-to-many relationship
public function posts(): RelationInterface
{
    return Relation::hasManyRelation($this, Post::class, ['user_id' => 'id']);
}

// One-to-one relationship
public function profile(): RelationInterface
{
    return Relation::hasOneRelation($this, UserProfile::class, ['user_id' => 'id']);
}

// Many-to-many relationship
public function roles(): RelationInterface
{
    return Relation::manyToManyRelation(
        $this,
        Role::class,
        'user_roles',
        ['role_id' => 'id'],
        ['user_id' => 'id']
    );
}

// Loading with relationships
$usersWithPosts = yield User::find()
    ->with(['posts', 'profile'])
    ->all();
```

## DataMapper Pattern

### Basic Usage

```php
use App\Entities\UserEntity;
use App\Mappers\UserMapper;
use Amp\Loop;

Loop::run(function () {
    $userMapper = new UserMapper();
    
    // Create a new entity
    $user = new UserEntity([
        'username' => 'jane_doe',
        'email' => 'jane@example.com'
    ]);
    $user->hashPassword('secret456');
    
    // Save entity
    $saved = yield $userMapper->save($user);
    if ($saved) {
        echo "User saved with ID: " . $user->getId();
    }
    
    // Find entity by ID
    $foundUser = yield $userMapper->findById(1);
    
    // Find entities by criteria
    $activeUsers = yield $userMapper->findBy(['status' => 1]);
    
    // Update entity
    $foundUser->setEmail('newemail@example.com');
    yield $userMapper->save($foundUser);
    
    // Delete entity
    yield $userMapper->delete($foundUser);
});
```

### Entity Definition

```php
<?php

namespace App\Entities;

class UserEntity
{
    public ?int $id = null;
    public ?string $username = null;
    public ?string $email = null;
    public ?string $password = null;
    public int $status = 1;
    public ?\DateTime $createdAt = null;
    public ?\DateTime $updatedAt = null;
    
    // Getters and setters...
    
    public function hashPassword(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_DEFAULT);
    }
    
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }
}
```

### Mapper Definition

```php
<?php

namespace App\Mappers;

use HybridPHP\Core\Database\ORM\DataMapper;
use App\Entities\UserEntity;

class UserMapper extends DataMapper
{
    protected function initialize(): void
    {
        $this->setTableName('users');
        $this->setEntityClass(UserEntity::class);
        $this->setPrimaryKey('id');
        $this->setFieldMapping([
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
        ]);
    }
    
    protected function convertFromDatabase(string $property, $value)
    {
        switch ($property) {
            case 'createdAt':
            case 'updatedAt':
                return $value ? new \DateTime($value) : null;
            default:
                return $value;
        }
    }
    
    protected function convertToDatabase(string $property, $value)
    {
        switch ($property) {
            case 'createdAt':
            case 'updatedAt':
                return $value instanceof \DateTime ? $value->format('Y-m-d H:i:s') : $value;
            default:
                return $value;
        }
    }
}
```

## Validation System

### Built-in Validators

- **required**: Field must not be empty
- **string**: Must be a string with optional min/max length
- **integer**: Must be an integer
- **email**: Must be a valid email address
- **unique**: Must be unique in the database

### Custom Validation

```php
public function rules(): array
{
    return [
        [['username'], 'required'],
        [['username'], 'string', ['min' => 3, 'max' => 50]],
        [['email'], 'email'],
        [['username', 'email'], 'unique'],
        [['age'], 'integer', ['min' => 18, 'max' => 120]],
    ];
}
```

## Query Building

### Chainable Queries

```php
// Complex query with joins and conditions
$posts = yield Post::find()
    ->select(['posts.*', 'users.username'])
    ->innerJoin('users', 'users.id = posts.user_id')
    ->where(['posts.status' => 1])
    ->andWhere(['>', 'posts.created_at', '2023-01-01'])
    ->orderBy(['posts.created_at' => 'DESC'])
    ->limit(10)
    ->all();

// Count queries
$count = yield User::find()
    ->where(['status' => 1])
    ->count();

// Existence checks
$exists = yield User::find()
    ->where(['email' => 'test@example.com'])
    ->exists();
```

## Transactions

### ActiveRecord Transactions

```php
use HybridPHP\Core\Database\DatabaseInterface;

$db = Container::getInstance()->get(DatabaseInterface::class);

$result = yield $db->transaction(function ($db) {
    $user = new User(['username' => 'user1', 'email' => 'user1@example.com']);
    $post = new Post(['title' => 'Post 1', 'content' => 'Content...']);
    
    $userSaved = yield $user->save();
    $post->user_id = $user->id;
    $postSaved = yield $post->save();
    
    if (!$userSaved || !$postSaved) {
        throw new \Exception('Failed to save');
    }
    
    return ['user' => $user->id, 'post' => $post->id];
});
```

### DataMapper Transactions

```php
$userMapper = new UserMapper();

$result = yield $userMapper->transaction(function () use ($userMapper) {
    $user1 = new UserEntity(['username' => 'user1', 'email' => 'user1@example.com']);
    $user2 = new UserEntity(['username' => 'user2', 'email' => 'user2@example.com']);
    
    $saved1 = yield $userMapper->save($user1);
    $saved2 = yield $userMapper->save($user2);
    
    if (!$saved1 || !$saved2) {
        throw new \Exception('Failed to save users');
    }
    
    return ['user1' => $user1->getId(), 'user2' => $user2->getId()];
});
```

## Performance Considerations

1. **Connection Pooling**: The ORM uses the underlying connection pool for optimal performance
2. **Lazy Loading**: Relationships are loaded on-demand
3. **Query Optimization**: Use `select()` to limit columns and `limit()` for pagination
4. **Batch Operations**: Use static methods like `updateAll()` and `deleteAll()` for bulk operations
5. **Caching**: Consider implementing query result caching for frequently accessed data

## Best Practices

1. **Use Validation**: Always validate data before saving
2. **Handle Errors**: Wrap database operations in try-catch blocks
3. **Use Transactions**: Group related operations in transactions
4. **Optimize Queries**: Use joins instead of N+1 queries
5. **Type Safety**: Use proper type hints and return types
6. **Testing**: Write unit tests for your models and mappers

## Architecture

The ORM system is built on top of the HybridPHP database layer and follows these principles:

- **Asynchronous First**: All operations return Promises
- **PSR Compliance**: Follows PSR standards where applicable
- **Modular Design**: Each component has a single responsibility
- **Extensible**: Easy to extend with custom validators and behaviors
- **Performance Oriented**: Optimized for high-concurrency scenarios

## Integration

The ORM integrates seamlessly with other HybridPHP components:

- **Container**: Uses dependency injection for database connections
- **Middleware**: Can be used in middleware for request processing
- **Events**: Supports lifecycle events (beforeSave, afterSave, etc.)
- **Logging**: Integrates with the logging system for query logging
- **Monitoring**: Provides metrics for performance monitoring