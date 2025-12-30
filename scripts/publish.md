# 快速发布命令参考

## 一、发布 Framework（在项目根目录执行）

```bash
# 1. 确保代码已提交
git add .
git commit -m "chore: prepare for v0.5.0 release"

# 2. 添加远程仓库（首次）
git remote add origin https://github.com/hybridphp/framework.git

# 3. 推送代码
git branch -M main
git push -u origin main

# 4. 创建并推送标签
git tag -a v0.5.0 -m "Release v0.5.0"
git push origin v0.5.0
```

## 二、发布 Skeleton（在 skeleton 目录执行）

```bash
# 1. 进入 skeleton 目录
cd skeleton

# 2. 初始化 git（首次）
git init
git add .
git commit -m "Initial commit - HybridPHP skeleton v0.5.0"

# 3. 添加远程仓库
git remote add origin https://github.com/hybridphp/skeleton.git

# 4. 推送代码
git branch -M main
git push -u origin main

# 5. 创建并推送标签
git tag -a v0.5.0 -m "Release v0.5.0"
git push origin v0.5.0
```

## 三、Packagist 提交

1. 访问 https://packagist.org/packages/submit
2. 输入 GitHub 仓库 URL
3. 点击 Check -> Submit

## 四、验证安装

```bash
# 测试 create-project
composer create-project hybridphp/skeleton test-app

# 进入并启动
cd test-app
php bootstrap.php
```
