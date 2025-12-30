# HybridPHP 发布到 GitHub 和 Packagist 完整指南

本指南将帮助你将 HybridPHP 框架发布到 GitHub 和 Packagist，让用户可以通过 `composer create-project` 快速创建项目。

## 📦 包结构说明

| 包名 | 类型 | 用途 | GitHub 仓库 |
|------|------|------|-------------|
| `hybridphp/framework` | library | 框架核心，作为依赖引入 | hybridphp/framework |
| `hybridphp/skeleton` | project | 项目骨架，用于 create-project | hybridphp/skeleton |

## 🚀 第一步：准备 GitHub 账号和组织

### 1.1 创建 GitHub 组织（推荐）

```
1. 登录 GitHub: https://github.com
2. 点击右上角 "+" -> "New organization"
3. 选择免费计划
4. 组织名称填写: hybridphp
5. 完成创建
```

### 1.2 或使用个人账号

如果不想创建组织，可以直接在个人账号下创建仓库，但包名需要改为：
- `your-username/framework`
- `your-username/skeleton`

## 🔧 第二步：发布 Framework 包（框架核心）

### 2.1 在 GitHub 创建 framework 仓库

```
1. 访问: https://github.com/new
2. Repository name: framework
3. Owner: hybridphp（选择你的组织）
4. Description: HybridPHP Framework - High-performance async PHP framework
5. 选择 Public
6. 不要勾选 "Add a README file"（我们已有）
7. 点击 "Create repository"
```

### 2.2 准备 framework 代码

在当前项目根目录，需要保留以下文件作为 framework 包：

```
framework/
├── core/                    # 框架核心代码
├── bin/
│   └── hybrid              # CLI 工具
├── composer.json           # 包配置
├── README.md               # 文档
├── LICENSE                 # 许可证
└── .gitignore
```

### 2.3 推送 framework 到 GitHub

```bash
# 如果当前仓库就是 framework，直接操作
# 添加远程仓库
git remote add origin https://github.com/hybridphp/framework.git

# 或者如果已有 origin，改名
git remote rename origin old-origin
git remote add origin https://github.com/hybridphp/framework.git

# 推送代码
git branch -M main
git push -u origin main

# 创建版本标签
git tag -a v0.5.0 -m "Release v0.5.0 - Initial stable release"
git push origin v0.5.0
```

### 2.4 创建 GitHub Release

```
1. 访问: https://github.com/hybridphp/framework/releases
2. 点击 "Create a new release"
3. Choose a tag: v0.5.0
4. Release title: v0.5.0 - Initial Release
5. 描述发布内容
6. 点击 "Publish release"
```

## 📁 第三步：发布 Skeleton 包（项目骨架）

### 3.1 在 GitHub 创建 skeleton 仓库

```
1. 访问: https://github.com/new
2. Repository name: skeleton
3. Owner: hybridphp
4. Description: HybridPHP Application Skeleton - Quick start template
5. 选择 Public
6. 点击 "Create repository"
```

### 3.2 将 skeleton 目录作为独立仓库推送

```bash
# 进入 skeleton 目录
cd skeleton

# 初始化 git 仓库
git init

# 添加所有文件
git add .

# 提交
git commit -m "Initial commit - HybridPHP skeleton v0.5.0"

# 添加远程仓库
git remote add origin https://github.com/hybridphp/skeleton.git

# 推送
git branch -M main
git push -u origin main

# 创建版本标签（与 framework 版本保持一致）
git tag -a v0.5.0 -m "Release v0.5.0 - Initial stable release"
git push origin v0.5.0
```

### 3.3 创建 GitHub Release

同 framework，在 skeleton 仓库创建 Release。

## 🌐 第四步：发布到 Packagist

### 4.1 注册 Packagist 账号

```
1. 访问: https://packagist.org
2. 点击 "Sign in" -> "Sign in with GitHub"
3. 授权 GitHub 账号
```

### 4.2 提交 framework 包

```
1. 登录 Packagist 后，点击 "Submit"
2. Repository URL: https://github.com/hybridphp/framework
3. 点击 "Check" 验证
4. 点击 "Submit" 提交
```

### 4.3 提交 skeleton 包

```
1. 点击 "Submit"
2. Repository URL: https://github.com/hybridphp/skeleton
3. 点击 "Check" 验证
4. 点击 "Submit" 提交
```

### 4.4 设置自动更新（重要！）

为了让 Packagist 自动同步 GitHub 的更新：

```
1. 在 GitHub 仓库设置中，进入 Settings -> Webhooks
2. 点击 "Add webhook"
3. Payload URL: https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME
4. Content type: application/json
5. Secret: 在 Packagist 个人设置中获取 API Token
6. 选择 "Just the push event"
7. 点击 "Add webhook"
```

或者使用 Packagist 的 GitHub 集成：
```
1. 在 Packagist 包页面，点击 "Settings"
2. 点击 "Enable GitHub Hook"
```

## ✅ 第五步：验证发布

### 5.1 测试 framework 包安装

```bash
# 创建测试目录
mkdir test-framework && cd test-framework

# 安装 framework 包
composer require hybridphp/framework

# 验证安装
ls vendor/hybridphp/framework
```

### 5.2 测试 create-project

```bash
# 使用 create-project 创建新项目
composer create-project hybridphp/skeleton my-test-app

# 进入项目
cd my-test-app

# 验证文件结构
ls -la

# 检查 .env 是否自动创建
cat .env

# 启动服务器测试
php bootstrap.php
```

## 📋 发布检查清单

### Framework 包检查

- [ ] `composer.json` 中 `name` 为 `hybridphp/framework`
- [ ] `type` 为 `library`
- [ ] 包含 `core/` 目录
- [ ] 包含 `bin/hybrid` CLI 工具
- [ ] `autoload` 配置正确
- [ ] 创建了 git tag
- [ ] 推送到 GitHub
- [ ] 提交到 Packagist
- [ ] 设置了 Webhook 自动更新

### Skeleton 包检查

- [ ] `composer.json` 中 `name` 为 `hybridphp/skeleton`
- [ ] `type` 为 `project`
- [ ] `require` 中包含 `hybridphp/framework: ^3.0`
- [ ] 包含 `post-create-project-cmd` 脚本
- [ ] 包含 `.env.example`
- [ ] 包含 `bootstrap.php`
- [ ] 创建了 git tag
- [ ] 推送到 GitHub
- [ ] 提交到 Packagist

## 🔄 版本更新流程

### 发布新版本

```bash
# 1. 更新代码并提交
git add .
git commit -m "feat: add new feature"

# 2. 更新版本号（遵循语义化版本）
# 修复 bug: v0.5.1
# 新功能: v3.1.0
# 破坏性更新: v4.0.0

# 3. 创建新标签
git tag -a v3.1.0 -m "Release v3.1.0"

# 4. 推送代码和标签
git push origin main
git push origin v3.1.0

# 5. 在 GitHub 创建 Release
# 6. Packagist 会自动同步（如果设置了 Webhook）
```

### 版本号规范

```
v主版本.次版本.修订版本

- 主版本: 不兼容的 API 变更
- 次版本: 向后兼容的功能新增
- 修订版本: 向后兼容的 bug 修复
```

## 🛠️ 常见问题

### Q: Packagist 显示 "No valid composer.json was found"

检查 `composer.json` 格式是否正确：
```bash
composer validate
```

### Q: create-project 后 .env 没有自动创建

确保 `skeleton/composer.json` 中有：
```json
"scripts": {
    "post-root-package-install": [
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
    ]
}
```

### Q: 找不到 hybridphp/framework 包

1. 确认包已提交到 Packagist
2. 等待几分钟让 Packagist 索引
3. 清除 Composer 缓存：`composer clear-cache`

### Q: 版本约束问题

skeleton 的 `composer.json` 中使用 `^3.0` 表示兼容 3.x 的所有版本：
```json
"require": {
    "hybridphp/framework": "^3.0"
}
```

## 📚 相关链接

- [Composer 官方文档](https://getcomposer.org/doc/)
- [Packagist 官方网站](https://packagist.org/)
- [语义化版本规范](https://semver.org/lang/zh-CN/)
- [GitHub Releases 文档](https://docs.github.com/en/repositories/releasing-projects-on-github)
