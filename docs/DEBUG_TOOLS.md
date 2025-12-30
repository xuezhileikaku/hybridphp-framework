# HybridPHP 调试与性能分析工具

HybridPHP 框架提供了一套完整的调试与性能分析工具，帮助开发者快速定位问题、优化性能和监控应用程序状态。

## 功能特性

### 🔍 详细错误页面和异常堆栈跟踪
- 美观的错误页面显示
- 完整的堆栈跟踪信息
- 源代码上下文显示
- 性能数据集成
- 请求信息收集

### 📊 性能分析
- 执行时间监控
- 内存使用分析
- 自定义计时器
- 内存快照
- 系统信息收集

### 🔄 协程状态监控
- 协程生命周期跟踪
- 慢协程检测
- 失败协程分析
- 协程统计信息
- 实时状态监控

### 🗄️ SQL查询分析
- 慢查询检测
- 重复查询识别
- N+1查询问题检测
- 查询性能统计
- 优化建议生成

### 📈 实时监控面板
- Web界面监控
- 实时数据更新
- 多标签页视图
- 导出功能
- API接口

## 快速开始

### 1. 配置调试工具

编辑 `config/debug.php` 文件：

```php
return [
    'debug' => true,
    'profiler' => [
        'enabled' => true,
    ],
    'coroutine_debugger' => [
        'enabled' => true,
        'slow_threshold' => 1.0,
    ],
    'query_analyzer' => [
        'enabled' => true,
        'slow_threshold' => 0.1,
    ],
];
```

### 2. 注册调试服务

在应用程序启动时注册调试服务：

```php
use HybridPHP\Core\Debug\DebugServiceProvider;

$container = new Container();
$debugProvider = new DebugServiceProvider($container, $debugConfig);
$debugProvider->register();
$debugProvider->boot();
```

### 3. 使用调试中间件

添加调试中间件到请求处理管道：

```php
use HybridPHP\Core\Middleware\DebugMiddleware;

$debugMiddleware = new DebugMiddleware(
    $container->get(PerformanceProfiler::class),
    $container->get(CoroutineDebugger::class),
    $container->get(QueryAnalyzer::class)
);

$app->addMiddleware($debugMiddleware);
```

## 使用指南

### 性能分析器

#### 基本使用

```php
use HybridPHP\Core\Debug\PerformanceProfiler;

$profiler = new PerformanceProfiler();

// 开始计时
$profiler->startTimer('database_query');

// 执行操作
$result = $database->query('SELECT * FROM users');

// 停止计时
$profiler->stopTimer('database_query');

// 记录内存快照
$profiler->recordMemorySnapshot('after_query');

// 获取报告
$report = $profiler->getDetailedReport();
```

#### 查询日志记录

```php
$profiler->logQuery(
    'SELECT * FROM users WHERE status = ?',
    ['active'],
    0.025, // 执行时间
    ['table' => 'users', 'type' => 'select']
);
```

### 协程调试器

#### 注册协程

```php
use HybridPHP\Core\Debug\CoroutineDebugger;

$debugger = new CoroutineDebugger();

$future = $debugger->registerCoroutine(
    'user_task_1',
    'User Processing Task',
    function() {
        // 协程逻辑
        return processUser();
    }
);

$result = $future->await();
```

#### 监控协程状态

```php
// 获取统计信息
$stats = $debugger->getStatistics();

// 获取活跃协程
$active = $debugger->getActiveCoroutines();

// 获取慢协程
$slow = $debugger->getSlowCoroutines();

// 获取失败协程
$failed = $debugger->getFailedCoroutines();
```

### 查询分析器

#### 记录查询

```php
use HybridPHP\Core\Debug\QueryAnalyzer;

$analyzer = new QueryAnalyzer();

$analyzer->recordQuery(
    'SELECT * FROM posts WHERE user_id = ?',
    [123],
    0.150 // 执行时间
);
```

#### 分析报告

```php
// 获取分析报告
$report = $analyzer->getAnalysisReport();

// 获取慢查询
$slowQueries = $analyzer->getSlowQueries();

// 获取重复查询
$duplicates = $analyzer->getDuplicateQueries();

// 获取统计信息
$stats = $analyzer->getStatistics();
```

### 错误处理器

#### 基本配置

```php
use HybridPHP\Core\Debug\DebugErrorHandler;

$errorHandler = new DebugErrorHandler($logger, true, $profiler);

// 设置源代码显示选项
$errorHandler->setSourceCodeOptions(true, 10);

// 注册错误处理器
set_error_handler([$errorHandler, 'handleError']);
set_exception_handler([$errorHandler, 'handleException']);
```

#### 添加调试信息

```php
$errorHandler->addDebugInfo('user_id', 123);
$errorHandler->addDebugInfo('request_id', 'req_12345');
```

## 命令行工具

### 基本命令

```bash
# 查看调试状态
php debug.php status

# 查看性能分析报告
php debug.php profiler

# 查看协程调试报告
php debug.php coroutines

# 查看查询分析报告
php debug.php queries

# 导出调试数据
php debug.php export json

# 清除调试数据
php debug.php clear

# 启用调试模式
php debug.php enable

# 禁用调试模式
php debug.php disable
```

### 示例输出

```
=== Performance Profiler Report ===

Execution Time: 1.2345s
Memory Used: 15.67 MB
Peak Memory: 18.23 MB
Query Count: 25
Total Query Time: 0.3456s

============================================================
EXECUTION TIMERS
============================================================
database_connection          0.0123s     2.34 MB
user_authentication         0.0456s     1.23 MB
data_processing              0.7890s    10.45 MB
```

## 监控面板

### 访问面板

访问 `http://your-app.com/monitoring/debug` 查看实时调试面板。

### 面板功能

- **概览页面**: 显示调试状态和性能摘要
- **性能分析器**: 执行计时器和内存快照
- **协程监控**: 协程状态和统计信息
- **查询分析**: SQL查询性能和问题

### API接口

```bash
# 获取性能数据
GET /monitoring/api/performance

# 获取协程数据
GET /monitoring/api/coroutines

# 获取查询数据
GET /monitoring/api/queries

# 获取分析器数据
GET /monitoring/api/profiler
```

## 配置选项

### 性能分析器配置

```php
'profiler' => [
    'enabled' => true,
    'collect_timers' => true,
    'collect_memory_snapshots' => true,
    'collect_query_data' => true,
    'max_snapshots' => 100,
],
```

### 协程调试器配置

```php
'coroutine_debugger' => [
    'enabled' => true,
    'slow_threshold' => 1.0, // 秒
    'collect_stacks' => true,
    'max_stack_depth' => 20,
    'monitor_interval' => 5, // 秒
],
```

### 查询分析器配置

```php
'query_analyzer' => [
    'enabled' => true,
    'slow_threshold' => 0.1, // 秒
    'max_queries' => 1000,
    'detect_n_plus_one' => true,
    'detect_duplicates' => true,
],
```

## 最佳实践

### 1. 生产环境注意事项

- 在生产环境中禁用调试模式
- 限制监控面板访问权限
- 定期清理调试数据
- 监控性能影响

### 2. 性能优化建议

- 根据慢查询报告优化数据库查询
- 使用协程监控识别瓶颈
- 定期分析内存使用模式
- 监控响应时间趋势

### 3. 故障排查流程

1. 查看错误日志和异常信息
2. 分析性能分析器报告
3. 检查协程状态和失败原因
4. 审查SQL查询性能
5. 导出数据进行深入分析

## 扩展开发

### 自定义分析器

```php
class CustomAnalyzer
{
    public function analyze($data)
    {
        // 自定义分析逻辑
        return $analysis;
    }
}
```

### 集成外部工具

```php
// 集成 Xdebug
if (extension_loaded('xdebug')) {
    xdebug_start_trace();
}

// 集成 New Relic
if (extension_loaded('newrelic')) {
    newrelic_start_transaction('app');
}
```

## 故障排除

### 常见问题

1. **调试面板无法访问**
   - 检查路由配置
   - 验证认证设置
   - 确认服务注册

2. **性能数据不准确**
   - 检查计时器配对
   - 验证内存测量点
   - 确认配置正确

3. **协程监控失效**
   - 检查协程注册
   - 验证异步环境
   - 确认回调设置

### 调试技巧

- 使用日志记录关键信息
- 分阶段启用调试功能
- 对比不同环境数据
- 利用导出功能分析

## 更新日志

### v1.0.0
- 初始版本发布
- 基础调试功能
- 监控面板
- 命令行工具

---

更多信息请参考 [HybridPHP 官方文档](https://hybridphp.org/docs)。