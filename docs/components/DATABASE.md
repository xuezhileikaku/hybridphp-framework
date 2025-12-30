# 数据库 ORM

HybridPHP 提供功能完善的异步 ORM 系统，支持 ActiveRecord 和 DataMapper 两种模式。

## 核心特性

- **双模式支持**: ActiveRecord 和 DataMapper
- **完全异步**: 所有数据库操作非阻塞
- **Yii2 风格 API**: 熟悉直观的接口
- **关系映射**: 一对一、一对多、多对多
- **数据验证**: 内置验证系统
- **事务支持**: 完整的事务和回滚

## ActiveRecord 模式

### 模型定义

```php
namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;

class User extends ActiveRecord
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
    
    // 验证规则
    public function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            [['email'], 'email'],
            [['email'], 'unique'],
        ];
    }
    
    // 关联关系
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
    
    public function profile()
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }
    
    // 访问器
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
    
    // 修改器
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}
```

### CRUD 操作

```php
// 创建
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
])->await();

// 查找
$user = User::find(1)->await();
$user = User::findOne(['email' => 'john@example.com'])->await();

// 更新
$user->name = 'Jane Doe';
$user->save()->await();

// 删除
$user->delete()->await();
```

### 查询构建

```php
// 基础查询
$users = User::query()
    ->where('status', 'active')
    ->get()->await();

// 复杂查询
$users = User::query()
    ->where('age', '>', 18)
    ->where('city', 'Beijing')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()->await();

// 关联查询
$users = User::query()
    ->with(['posts', 'profile'])
    ->where('status', 'active')
    ->get()->await();

// 聚合查询
$count = User::query()->where('status', 'active')->count()->await();
$avgAge = User::query()->avg('age')->await();
$maxSalary = User::query()->max('salary')->await();

// 分组查询
$stats = User::query()
    ->select(['city', 'COUNT(*) as count'])
    ->groupBy('city')
    ->get()->await();
```

### 关联关系

```php
// 一对多
public function posts()
{
    return $this->hasMany(Post::class, 'user_id');
}

// 一对一
public function profile()
{
    return $this->hasOne(UserProfile::class, 'user_id');
}

// 多对多
public function roles()
{
    return $this->belongsToMany(
        Role::class,
        'user_roles',
        'user_id',
        'role_id'
    );
}

// 使用关联
$user = User::find(1)->await();
$posts = $user->posts()->await();

// 预加载
$users = User::query()
    ->with(['posts', 'profile', 'roles'])
    ->get()->await();
```

## DataMapper 模式

### 实体定义

```php
namespace App\Entities;

class UserEntity
{
    public ?int $id = null;
    public ?string $username = null;
    public ?string $email = null;
    public ?string $password = null;
    public int $status = 1;
    public ?\DateTime $createdAt = null;
    
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

### Mapper 定义

```php
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
        ]);
    }
    
    protected function convertFromDatabase(string $property, $value)
    {
        if ($property === 'createdAt') {
            return $value ? new \DateTime($value) : null;
        }
        return $value;
    }
    
    protected function convertToDatabase(string $property, $value)
    {
        if ($property === 'createdAt' && $value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value;
    }
}
```

### 使用 Mapper

```php
$userMapper = new UserMapper();

// 创建
$user = new UserEntity([
    'username' => 'jane_doe',
    'email' => 'jane@example.com'
]);
$user->hashPassword('secret456');
$userMapper->save($user)->await();

// 查找
$user = $userMapper->findById(1)->await();
$users = $userMapper->findBy(['status' => 1])->await();

// 更新
$user->email = 'newemail@example.com';
$userMapper->save($user)->await();

// 删除
$userMapper->delete($user)->await();
```

## 事务处理

```php
$db = $container->get(DatabaseInterface::class);

$result = $db->transaction(function () {
    return async(function () {
        $user = User::create($userData)->await();
        Profile::create(['user_id' => $user->id] + $profileData)->await();
        return $user;
    });
})->await();
```

## 数据库迁移

### 创建迁移

```bash
php bin/hybrid make:migration create_users_table --create=users
```

### 迁移文件

```php
use HybridPHP\Core\Database\Migration\AbstractMigration;

class CreateUsersTable extends AbstractMigration
{
    public function up($database)
    {
        return $this->createTable('users', [
            'id' => ['type' => 'INT', 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255],
            'email' => ['type' => 'VARCHAR', 'length' => 255],
            'password' => ['type' => 'VARCHAR', 'length' => 255],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
        ], [
            'primary_key' => 'id',
            'unique' => ['email'],
        ]);
    }

    public function down($database)
    {
        return $this->dropTable('users');
    }
}
```

### 运行迁移

```bash
php bin/hybrid migrate              # 运行迁移
php bin/hybrid migrate --rollback   # 回滚
php bin/hybrid migrate:status       # 查看状态
```

## 数据填充

```php
use HybridPHP\Core\Database\Seeder\AbstractSeeder;

class UserSeeder extends AbstractSeeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->await();
    }
}
```

```bash
php bin/hybrid seed
```

## 连接池

```php
// config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'pool' => [
                'min_connections' => 5,
                'max_connections' => 20,
                'idle_timeout' => 60,
            ],
        ],
    ],
];
```

## 最佳实践

1. **使用验证**: 保存前验证数据
2. **使用事务**: 相关操作放在事务中
3. **预加载关联**: 避免 N+1 查询问题
4. **限制查询列**: 使用 `select()` 只查询需要的列
5. **使用索引**: 为常用查询字段添加索引

## 下一步

- [缓存系统](./CACHE.md) - 查询结果缓存
- [安全系统](./SECURITY.md) - 数据加密
