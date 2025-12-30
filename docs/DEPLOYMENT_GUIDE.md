# HybridPHP Framework - 部署指南

## 概述

HybridPHP 是一个融合 Yii2 易用性、Workerman 多进程能力和 AMPHP 异步特性的高性能 PHP 框架。本指南将详细介绍如何在不同环境中部署 HybridPHP 应用。

## 系统要求

### 基础要求
- **PHP**: 8.1 或更高版本
- **扩展**: json, openssl, pcntl, posix, sockets
- **内存**: 最少 512MB，推荐 2GB+
- **操作系统**: Linux (推荐), macOS, Windows

### 数据库支持
- **MySQL**: 5.7+ 或 8.0+
- **PostgreSQL**: 12+
- **Redis**: 5.0+ (用于缓存和会话)

### 可选组件
- **Docker**: 容器化部署
- **Kubernetes**: 云原生部署
- **Nginx**: 反向代理和负载均衡
- **Prometheus**: 监控和指标收集
- **ELK Stack**: 日志分析

## 快速开始

### 1. 安装依赖

```bash
# 克隆项目
git clone https://github.com/hybridphp/framework.git
cd framework

# 安装 Composer 依赖
composer install --optimize-autoloader

# 复制环境配置
cp .env.example .env
```

### 2. 环境配置

编辑 `.env` 文件：

```env
# 应用配置
APP_NAME="HybridPHP Application"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hybridphp
DB_USERNAME=root
DB_PASSWORD=your_password

# Redis 配置
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# 服务器配置
HTTP_HOST=0.0.0.0
HTTP_PORT=8080
HTTP_WORKERS=4

# 安全配置
APP_ENCRYPTION_KEY=your-64-character-encryption-key-here
JWT_SECRET=your-jwt-secret-key
```

### 3. 数据库初始化

```bash
# 运行数据库迁移
php bin/hybrid migrate

# 填充初始数据（可选）
php bin/hybrid seed
```

### 4. 启动应用

```bash
# 开发环境
php bootstrap.php

# 生产环境（后台运行）
nohup php bootstrap.php > /dev/null 2>&1 &
```

## 生产环境部署

### 1. 传统服务器部署

#### 系统准备

```bash
# 安装 PHP 8.1+
sudo apt update
sudo apt install php8.1 php8.1-cli php8.1-fpm php8.1-mysql php8.1-redis php8.1-json php8.1-openssl

# 安装 Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 安装 MySQL
sudo apt install mysql-server

# 安装 Redis
sudo apt install redis-server
```

#### 应用部署

```bash
# 创建应用目录
sudo mkdir -p /var/www/hybridphp
cd /var/www/hybridphp

# 部署代码
git clone https://github.com/your-org/your-app.git .
composer install --no-dev --optimize-autoloader

# 设置权限
sudo chown -R www-data:www-data /var/www/hybridphp
sudo chmod -R 755 /var/www/hybridphp
sudo chmod -R 777 storage/

# 配置环境
cp .env.example .env
# 编辑 .env 文件设置生产环境配置

# 初始化数据库
php bin/hybrid migrate --force
```

#### Nginx 配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    
    # 重定向到 HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    # SSL 配置
    ssl_certificate /path/to/your/certificate.crt;
    ssl_certificate_key /path/to/your/private.key;
    
    # 反向代理到 HybridPHP
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # WebSocket 支持
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
    
    # 静态文件直接服务
    location /static/ {
        alias /var/www/hybridphp/public/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

#### 系统服务配置

创建 systemd 服务文件 `/etc/systemd/system/hybridphp.service`：

```ini
[Unit]
Description=HybridPHP Application Server
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/hybridphp
ExecStart=/usr/bin/php bootstrap.php
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

启动服务：

```bash
sudo systemctl daemon-reload
sudo systemctl enable hybridphp
sudo systemctl start hybridphp
sudo systemctl status hybridphp
```

### 2. Docker 容器化部署

#### Dockerfile 优化

```dockerfile
# 多阶段构建
FROM php:8.1-cli-alpine AS builder

# 安装构建依赖
RUN apk add --no-cache \
    git \
    unzip \
    autoconf \
    gcc \
    g++ \
    make

# 安装 PHP 扩展
RUN docker-php-ext-install \
    pcntl \
    sockets \
    pdo_mysql

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制源代码
WORKDIR /app
COPY . .

# 安装依赖
RUN composer install --no-dev --optimize-autoloader --no-scripts

# 生产镜像
FROM php:8.1-cli-alpine

# 安装运行时依赖
RUN apk add --no-cache \
    mysql-client \
    redis

# 安装 PHP 扩展
RUN docker-php-ext-install \
    pcntl \
    sockets \
    pdo_mysql

# 创建应用用户
RUN addgroup -g 1000 app && \
    adduser -u 1000 -G app -s /bin/sh -D app

# 复制应用文件
WORKDIR /app
COPY --from=builder --chown=app:app /app .

# 设置权限
RUN chmod -R 755 /app && \
    chmod -R 777 storage/

# 切换到应用用户
USER app

# 健康检查
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

# 暴露端口
EXPOSE 8080

# 启动命令
CMD ["php", "bootstrap.php"]
```

#### Docker Compose 配置

```yaml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      - APP_ENV=production
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis
    volumes:
      - ./storage:/app/storage
    restart: unless-stopped
    
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root_password
      MYSQL_DATABASE: hybridphp
      MYSQL_USER: hybridphp
      MYSQL_PASSWORD: hybridphp_password
    volumes:
      - mysql_data:/var/lib/mysql
    restart: unless-stopped
    
  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
    restart: unless-stopped
    
  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx.conf:/etc/nginx/nginx.conf
      - ./storage/ssl:/etc/nginx/ssl
    depends_on:
      - app
    restart: unless-stopped

volumes:
  mysql_data:
  redis_data:
```

### 3. Kubernetes 云原生部署

#### 命名空间和配置

```yaml
# namespace.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: hybridphp-production
---
# configmap.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: hybridphp-config
  namespace: hybridphp-production
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  DB_HOST: "mysql-service"
  REDIS_HOST: "redis-service"
  HTTP_HOST: "0.0.0.0"
  HTTP_PORT: "8080"
  HTTP_WORKERS: "4"
```

#### 密钥管理

```yaml
# secrets.yaml
apiVersion: v1
kind: Secret
metadata:
  name: hybridphp-secrets
  namespace: hybridphp-production
type: Opaque
data:
  db-password: <base64-encoded-password>
  redis-password: <base64-encoded-password>
  app-encryption-key: <base64-encoded-key>
  jwt-secret: <base64-encoded-secret>
```

#### 应用部署

```yaml
# deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: hybridphp-app
  namespace: hybridphp-production
spec:
  replicas: 3
  selector:
    matchLabels:
      app: hybridphp
  template:
    metadata:
      labels:
        app: hybridphp
    spec:
      containers:
      - name: hybridphp
        image: your-registry/hybridphp:latest
        ports:
        - containerPort: 8080
        env:
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: hybridphp-secrets
              key: db-password
        envFrom:
        - configMapRef:
            name: hybridphp-config
        resources:
          requests:
            memory: "512Mi"
            cpu: "500m"
          limits:
            memory: "1Gi"
            cpu: "1000m"
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /health/ready
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
```

#### 服务和入口

```yaml
# service.yaml
apiVersion: v1
kind: Service
metadata:
  name: hybridphp-service
  namespace: hybridphp-production
spec:
  selector:
    app: hybridphp
  ports:
  - port: 80
    targetPort: 8080
  type: ClusterIP
---
# ingress.yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: hybridphp-ingress
  namespace: hybridphp-production
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
spec:
  tls:
  - hosts:
    - your-domain.com
    secretName: hybridphp-tls
  rules:
  - host: your-domain.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: hybridphp-service
            port:
              number: 80
```

## 监控和日志

### 1. Prometheus 监控

```yaml
# prometheus-config.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: prometheus-config
data:
  prometheus.yml: |
    global:
      scrape_interval: 15s
    scrape_configs:
    - job_name: 'hybridphp'
      static_configs:
      - targets: ['hybridphp-service:80']
      metrics_path: '/metrics'
      scrape_interval: 10s
```

### 2. 日志收集

```yaml
# fluentd-config.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: fluentd-config
data:
  fluent.conf: |
    <source>
      @type tail
      path /app/storage/logs/*.log
      pos_file /var/log/fluentd-hybridphp.log.pos
      tag hybridphp.*
      format json
    </source>
    
    <match hybridphp.**>
      @type elasticsearch
      host elasticsearch-service
      port 9200
      index_name hybridphp
    </match>
```

## 性能优化

### 1. PHP 配置优化

```ini
; php.ini 优化配置
memory_limit = 1G
max_execution_time = 300
max_input_time = 300

; OPcache 配置
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.save_comments = 0

; 进程配置
pcntl.async_signals = 1
```

### 2. 应用配置优化

```php
// config/main.php
return [
    'cache' => [
        'enabled' => true,
        'default' => 'redis',
        'prefix' => 'hybridphp:',
    ],
    
    'database' => [
        'pool' => [
            'min' => 10,
            'max' => 100,
            'idle_timeout' => 60,
        ],
    ],
    
    'server' => [
        'worker_count' => 8, // CPU 核心数
        'max_connections' => 10000,
        'max_request' => 100000,
    ],
];
```

### 3. 数据库优化

```sql
-- MySQL 配置优化
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL innodb_log_file_size = 268435456; -- 256MB
SET GLOBAL max_connections = 1000;
SET GLOBAL query_cache_size = 67108864; -- 64MB

-- 索引优化
CREATE INDEX idx_user_email ON users(email);
CREATE INDEX idx_post_created_at ON posts(created_at);
```

## 安全配置

### 1. 防火墙配置

```bash
# UFW 防火墙配置
sudo ufw enable
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw deny 8080/tcp   # 禁止直接访问应用端口
```

### 2. SSL/TLS 配置

```bash
# 使用 Let's Encrypt 获取证书
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com

# 自动续期
sudo crontab -e
# 添加：0 12 * * * /usr/bin/certbot renew --quiet
```

### 3. 安全头配置

```nginx
# Nginx 安全头配置
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## 备份和恢复

### 1. 数据库备份

```bash
#!/bin/bash
# backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/hybridphp"
DB_NAME="hybridphp"

# 创建备份目录
mkdir -p $BACKUP_DIR

# 数据库备份
mysqldump -u root -p$DB_PASSWORD $DB_NAME > $BACKUP_DIR/db_$DATE.sql

# 压缩备份
gzip $BACKUP_DIR/db_$DATE.sql

# 删除 7 天前的备份
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +7 -delete
```

### 2. 应用备份

```bash
#!/bin/bash
# app_backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/hybridphp"
APP_DIR="/var/www/hybridphp"

# 备份应用文件
tar -czf $BACKUP_DIR/app_$DATE.tar.gz -C $APP_DIR \
    --exclude=vendor \
    --exclude=storage/logs \
    --exclude=storage/cache \
    .

# 删除 30 天前的备份
find $BACKUP_DIR -name "app_*.tar.gz" -mtime +30 -delete
```

## 故障排除

### 1. 常见问题

#### 应用无法启动
```bash
# 检查日志
tail -f storage/logs/app.log

# 检查端口占用
netstat -tlnp | grep 8080

# 检查进程
ps aux | grep php
```

#### 数据库连接失败
```bash
# 测试数据库连接
mysql -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE

# 检查数据库状态
systemctl status mysql
```

#### 内存不足
```bash
# 检查内存使用
free -h
top -p $(pgrep -f "php bootstrap.php")

# 调整 PHP 内存限制
echo "memory_limit = 2G" >> /etc/php/8.1/cli/php.ini
```

### 2. 性能问题诊断

```bash
# 检查系统负载
uptime
iostat -x 1

# 检查网络连接
ss -tuln | grep 8080
netstat -i

# 应用性能分析
curl -w "@curl-format.txt" -o /dev/null -s "http://localhost:8080/"
```

## 维护和更新

### 1. 应用更新

```bash
#!/bin/bash
# update.sh
cd /var/www/hybridphp

# 备份当前版本
cp -r . ../hybridphp_backup_$(date +%Y%m%d)

# 拉取最新代码
git pull origin main

# 更新依赖
composer install --no-dev --optimize-autoloader

# 运行迁移
php bin/hybrid migrate --force

# 重启服务
sudo systemctl restart hybridphp
```

### 2. 定期维护

```bash
# 清理日志
find storage/logs -name "*.log" -mtime +30 -delete

# 清理缓存
php bin/hybrid cache:clear

# 优化数据库
mysql -u root -p -e "OPTIMIZE TABLE users, posts, sessions;"

# 检查磁盘空间
df -h
```

## 总结

本部署指南涵盖了 HybridPHP 框架在各种环境中的部署方案，从简单的单机部署到复杂的 Kubernetes 集群部署。选择合适的部署方案取决于你的具体需求、技术栈和资源情况。

关键要点：
- 确保系统满足最低要求
- 正确配置环境变量和数据库
- 实施适当的安全措施
- 设置监控和日志收集
- 建立备份和恢复流程
- 制定维护和更新计划

通过遵循本指南，你可以成功部署一个高性能、可扩展的 HybridPHP 应用。