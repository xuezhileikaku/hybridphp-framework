<?php
#!/usr/bin/env php

// 测试运行�?
echo "🧪 LaboFrame 测试运行器\n";
echo "========================\n\n";

// 检查PHPUnit是否安装
if (!file_exists('vendor/bin/phpunit')) {
    echo "�?PHPUnit 未安装，请先运行: composer install --dev\n";
    exit(1);
}

// 运行测试
$commands = [
    'unit' => './vendor/bin/phpunit tests/unit --colors=always',
    'feature' => './vendor/bin/phpunit tests/feature --colors=always',
    'all' => './vendor/bin/phpunit --colors=always',
    'coverage' => './vendor/bin/phpunit --colors=always --coverage-html tests/coverage',
];

$choice = $argv[1] ?? 'all';

if (!isset($commands[$choice])) {
    echo "�?无效的测试类型。可用选项:\n";
    echo "  unit     - 运行单元测试\n";
    echo "  feature  - 运行集成测试\n";
    echo "  all      - 运行所有测试\n";
    echo "  coverage - 生成覆盖率报告\n";
    exit(1);
}

echo "🚀 运行 {$choice} 测试...\n";
echo "========================\n\n";

passthru($commands[$choice], $exitCode);

if ($exitCode === 0) {
    echo "\n�?所有测试通过！\n";
} else {
    echo "\n�?测试失败！\n";
}

exit($exitCode);
