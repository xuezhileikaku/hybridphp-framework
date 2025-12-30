# HybridPHP Framework - Containerization & Cloud-Native Deployment

This directory contains all the necessary configuration files and scripts for containerizing and deploying the HybridPHP Framework in various environments.

## 📁 Directory Structure

```
docker/
├── Dockerfile                 # Multi-stage Docker build
├── supervisord.conf          # Process management
├── nginx.conf               # Nginx reverse proxy config
├── redis/
│   └── redis.conf          # Redis configuration
├── mysql/
│   ├── init/               # Database initialization scripts
│   └── conf.d/             # MySQL configuration
├── prometheus/
│   └── prometheus.yml      # Monitoring configuration
├── grafana/
│   └── provisioning/       # Grafana dashboards and datasources
├── k8s/                    # Kubernetes manifests
│   ├── namespace.yaml
│   ├── configmap.yaml
│   ├── secrets.yaml
│   ├── mysql-deployment.yaml
│   ├── redis-deployment.yaml
│   ├── hybridphp-deployment.yaml
│   ├── nginx-deployment.yaml
│   ├── hpa.yaml           # Horizontal Pod Autoscaler
│   ├── ingress.yaml       # Ingress configuration
│   ├── monitoring.yaml    # Monitoring setup
│   └── deploy.sh          # Deployment script
└── README.md              # This file
```

## 🚀 Quick Start

### Development Environment

```bash
# Start development environment
docker-compose up -d

# View logs
docker-compose logs -f hybridphp

# Stop environment
docker-compose down
```

### Production Environment

```bash
# Copy and configure environment
cp .env.example .env.production
# Edit .env.production with your production values

# Start production environment
docker-compose -f docker-compose.prod.yml up -d

# Enable monitoring (optional)
docker-compose -f docker-compose.prod.yml --profile monitoring up -d
```

## 🏗️ Docker Build Stages

The Dockerfile uses multi-stage builds for optimization:

1. **base**: Base PHP image with extensions
2. **dependencies**: Composer dependencies installation
3. **production**: Optimized production image
4. **development**: Development image with dev dependencies

### Building Images

```bash
# Build development image
docker build -t hybridphp:dev --target development .

# Build production image
docker build -t hybridphp:prod --target production .

# Build with specific PHP version
docker build --build-arg PHP_VERSION=8.2 -t hybridphp:php82 .
```

## ☸️ Kubernetes Deployment

### Prerequisites

- Kubernetes cluster (1.20+)
- kubectl configured
- Docker registry access
- Ingress controller (nginx-ingress recommended)
- cert-manager (for SSL certificates)

### Deployment Steps

1. **Configure environment**:
   ```bash
   export DOCKER_REGISTRY=your-registry.com
   export IMAGE_TAG=v1.0.0
   ```

2. **Deploy using script**:
   ```bash
   cd docker/k8s
   ./deploy.sh deploy
   ```

3. **Manual deployment**:
   ```bash
   # Create namespace and secrets
   kubectl apply -f namespace.yaml
   kubectl apply -f secrets.yaml
   kubectl apply -f configmap.yaml
   
   # Deploy database and cache
   kubectl apply -f mysql-deployment.yaml
   kubectl apply -f redis-deployment.yaml
   
   # Deploy application
   kubectl apply -f hybridphp-deployment.yaml
   kubectl apply -f nginx-deployment.yaml
   
   # Configure autoscaling and ingress
   kubectl apply -f hpa.yaml
   kubectl apply -f ingress.yaml
   
   # Setup monitoring
   kubectl apply -f monitoring.yaml
   ```

### Scaling

```bash
# Manual scaling
kubectl scale deployment hybridphp --replicas=5 -n hybridphp

# Auto-scaling is configured via HPA
kubectl get hpa -n hybridphp
```

## 📊 Monitoring & Observability

### Metrics Collection

- **Prometheus**: Metrics collection and alerting
- **Grafana**: Visualization and dashboards
- **ELK Stack**: Log aggregation and analysis

### Health Checks

- Application health: `http://localhost:8080/api/v1/health`
- Nginx health: `http://localhost/health`
- Prometheus metrics: `http://localhost:8080/api/v1/metrics`

### Accessing Monitoring

- Grafana: `http://localhost:3000` (admin/admin)
- Prometheus: `http://localhost:9090`
- Kibana: `http://localhost:5601`

## 🔒 Security Features

### Container Security

- Non-root user execution
- Read-only root filesystem where possible
- Security context constraints
- Network policies for pod-to-pod communication

### SSL/TLS Configuration

```bash
# Generate self-signed certificates for development
mkdir -p storage/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout storage/ssl/key.pem \
  -out storage/ssl/cert.pem \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=localhost"
```

### Secrets Management

- Kubernetes secrets for sensitive data
- Environment variable injection
- ConfigMaps for non-sensitive configuration

## 🔧 Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `development` |
| `APP_DEBUG` | Debug mode | `true` |
| `DB_HOST` | Database host | `mysql` |
| `DB_PORT` | Database port | `3306` |
| `REDIS_HOST` | Redis host | `redis` |
| `REDIS_PORT` | Redis port | `6379` |
| `PROMETHEUS_ENABLED` | Enable metrics | `true` |

### Resource Limits

#### Development
- CPU: 0.5 cores
- Memory: 256MB

#### Production
- CPU: 1.0 cores
- Memory: 512MB

## 🚨 Troubleshooting

### Common Issues

1. **Container won't start**:
   ```bash
   docker logs hybridphp_app
   kubectl logs -l app=hybridphp -n hybridphp
   ```

2. **Database connection issues**:
   ```bash
   # Check database status
   docker-compose exec mysql mysqladmin ping
   kubectl exec -it mysql-0 -n hybridphp -- mysqladmin ping
   ```

3. **Redis connection issues**:
   ```bash
   # Test Redis connection
   docker-compose exec redis redis-cli ping
   kubectl exec -it redis-0 -n hybridphp -- redis-cli ping
   ```

### Performance Tuning

1. **Adjust PHP-FPM settings** in `docker/php-fpm.conf`
2. **Tune MySQL configuration** in `docker/mysql/conf.d/`
3. **Configure Redis memory** in `docker/redis/redis.conf`
4. **Optimize Nginx** in `docker/nginx.conf`

## 📈 Scaling Strategies

### Horizontal Scaling

- HPA based on CPU/Memory usage
- Custom metrics scaling (requests per second)
- Manual scaling for predictable load

### Vertical Scaling

- Increase resource limits in deployments
- Optimize application memory usage
- Database connection pool tuning

## 🔄 CI/CD Integration

### GitHub Actions Example

```yaml
name: Deploy to Kubernetes
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    - name: Build and push
      run: |
        docker build -t ${{ secrets.REGISTRY }}/hybridphp:${{ github.sha }} .
        docker push ${{ secrets.REGISTRY }}/hybridphp:${{ github.sha }}
    - name: Deploy
      run: |
        export IMAGE_TAG=${{ github.sha }}
        ./docker/k8s/deploy.sh
```

## 📚 Additional Resources

- [Docker Best Practices](https://docs.docker.com/develop/dev-best-practices/)
- [Kubernetes Documentation](https://kubernetes.io/docs/)
- [Prometheus Monitoring](https://prometheus.io/docs/)
- [Grafana Dashboards](https://grafana.com/docs/)

## 🤝 Contributing

When adding new containerization features:

1. Update this README
2. Add appropriate health checks
3. Include monitoring configuration
4. Test in both development and production modes
5. Update Kubernetes manifests if needed