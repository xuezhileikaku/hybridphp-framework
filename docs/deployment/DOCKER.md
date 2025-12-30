# Docker 部署

本文档介绍如何使用 Docker 部署 HybridPHP 应用。

## 快速开始

### Dockerfile

```dockerfile
FROM php:8.2-cli

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo_mysql zip pcntl sockets

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 设置工作目录
WORKDIR /var/www/app

# 复制应用代码
COPY . .

# 安装依赖
RUN composer install --no-dev --optimize-autoloader

# 创建存储目录
RUN mkdir -p storage/logs storage/cache \
    && chmod -R 777 storage

# 暴露端口
EXPOSE 8080

# 启动命令
CMD ["php", "bootstrap.php"]
```

### Docker Compose

```yaml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_HOST=mysql
      - DB_DATABASE=hybridphp
      - DB_USERNAME=root
      - DB_PASSWORD=secret
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis
    volumes:
      - ./storage/logs:/var/www/app/storage/logs
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=secret
      - MYSQL_DATABASE=hybridphp
    volumes:
      - mysql_data:/var/lib/mysql
    ports:
      - "3306:3306"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data

volumes:
  mysql_data:
  redis_data:
```

## 生产环境配置

### 多阶段构建

```dockerfile
# 构建阶段
FROM composer:2 AS builder

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# 运行阶段
FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    libzip \
    && docker-php-ext-install pdo_mysql pcntl sockets

WORKDIR /var/www/app

COPY --from=builder /app /var/www/app

RUN adduser -D -u 1000 appuser \
    && chown -R appuser:appuser /var/www/app

USER appuser

EXPOSE 8080

CMD ["php", "bootstrap.php"]
```

### 生产 Docker Compose

```yaml
version: '3.8'

services:
  app:
    image: hybridphp/app:latest
    deploy:
      replicas: 3
      resources:
        limits:
          cpus: '1'
          memory: 512M
        reservations:
          cpus: '0.5'
          memory: 256M
      restart_policy:
        condition: on-failure
        delay: 5s
        max_attempts: 3
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 30s
      timeout: 10s
      retries: 3
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro
    depends_on:
      - app

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql_root_password
    secrets:
      - mysql_root_password
    volumes:
      - mysql_data:/var/lib/mysql
    deploy:
      resources:
        limits:
          memory: 1G

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes
    volumes:
      - redis_data:/data

secrets:
  mysql_root_password:
    external: true

volumes:
  mysql_data:
  redis_data:
```

## 常用命令

```bash
# 构建镜像
docker build -t hybridphp-app .

# 启动服务
docker-compose up -d

# 查看日志
docker-compose logs -f app

# 扩展服务
docker-compose up -d --scale app=3

# 进入容器
docker-compose exec app sh

# 运行迁移
docker-compose exec app php bin/hybrid migrate

# 停止服务
docker-compose down

# 清理资源
docker-compose down -v --rmi all
```

## 健康检查

```yaml
healthcheck:
  test: ["CMD", "php", "-r", "echo file_get_contents('http://localhost:8080/health');"]
  interval: 30s
  timeout: 10s
  retries: 3
  start_period: 40s
```

## 日志管理

```yaml
logging:
  driver: "json-file"
  options:
    max-size: "10m"
    max-file: "3"
    labels: "app,env"
    env: "APP_ENV"
```

## 网络配置

```yaml
networks:
  frontend:
    driver: bridge
  backend:
    driver: bridge
    internal: true

services:
  app:
    networks:
      - frontend
      - backend
  
  mysql:
    networks:
      - backend
```

## 下一步

- [Kubernetes 部署](./KUBERNETES.md) - K8s 集群部署
- [性能优化](./PERFORMANCE.md) - 性能调优
- [监控告警](./MONITORING.md) - 监控系统
