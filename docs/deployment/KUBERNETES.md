# Kubernetes 部署

本文档介绍如何在 Kubernetes 集群中部署 HybridPHP 应用。

## 快速开始

### Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: hybridphp-app
  labels:
    app: hybridphp
spec:
  replicas: 3
  selector:
    matchLabels:
      app: hybridphp
  template:
    metadata:
      labels:
        app: hybridphp
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9090"
    spec:
      containers:
      - name: app
        image: hybridphp/app:latest
        ports:
        - containerPort: 8080
        env:
        - name: APP_ENV
          value: "production"
        - name: DB_HOST
          valueFrom:
            secretKeyRef:
              name: db-secret
              key: host
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: db-secret
              key: password
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        readinessProbe:
          httpGet:
            path: /health/ready
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 10
        livenessProbe:
          httpGet:
            path: /health/live
            port: 8080
          initialDelaySeconds: 15
          periodSeconds: 20
```

### Service

```yaml
apiVersion: v1
kind: Service
metadata:
  name: hybridphp-service
spec:
  selector:
    app: hybridphp
  ports:
  - port: 80
    targetPort: 8080
  type: ClusterIP
```

### Ingress

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: hybridphp-ingress
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
spec:
  tls:
  - hosts:
    - api.example.com
    secretName: hybridphp-tls
  rules:
  - host: api.example.com
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

## ConfigMap 和 Secret

### ConfigMap

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: hybridphp-config
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  LOG_LEVEL: "info"
  CACHE_DRIVER: "redis"
```

### Secret

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: db-secret
type: Opaque
stringData:
  host: mysql.default.svc.cluster.local
  database: hybridphp
  username: app
  password: your-secure-password
```

## 水平自动扩缩容

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: hybridphp-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: hybridphp-app
  minReplicas: 3
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
```

## 持久化存储

```yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: hybridphp-logs
spec:
  accessModes:
    - ReadWriteMany
  resources:
    requests:
      storage: 10Gi
  storageClassName: standard
---
# 在 Deployment 中挂载
volumes:
- name: logs
  persistentVolumeClaim:
    claimName: hybridphp-logs
volumeMounts:
- name: logs
  mountPath: /var/www/app/storage/logs
```

## 蓝绿部署

```yaml
# blue-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: hybridphp-blue
  labels:
    app: hybridphp
    version: blue
spec:
  replicas: 3
  selector:
    matchLabels:
      app: hybridphp
      version: blue
  template:
    metadata:
      labels:
        app: hybridphp
        version: blue
    spec:
      containers:
      - name: app
        image: hybridphp/app:v1.0.0
---
# green-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: hybridphp-green
  labels:
    app: hybridphp
    version: green
spec:
  replicas: 3
  selector:
    matchLabels:
      app: hybridphp
      version: green
  template:
    metadata:
      labels:
        app: hybridphp
        version: green
    spec:
      containers:
      - name: app
        image: hybridphp/app:v1.1.0
---
# 切换流量
apiVersion: v1
kind: Service
metadata:
  name: hybridphp-service
spec:
  selector:
    app: hybridphp
    version: green  # 切换到 green
  ports:
  - port: 80
    targetPort: 8080
```

## 滚动更新

```yaml
spec:
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
```

## 网络策略

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: hybridphp-network-policy
spec:
  podSelector:
    matchLabels:
      app: hybridphp
  policyTypes:
  - Ingress
  - Egress
  ingress:
  - from:
    - namespaceSelector:
        matchLabels:
          name: ingress-nginx
    ports:
    - protocol: TCP
      port: 8080
  egress:
  - to:
    - namespaceSelector:
        matchLabels:
          name: database
    ports:
    - protocol: TCP
      port: 3306
  - to:
    - namespaceSelector:
        matchLabels:
          name: cache
    ports:
    - protocol: TCP
      port: 6379
```

## 部署脚本

```bash
#!/bin/bash
# deploy.sh

NAMESPACE=${NAMESPACE:-default}
IMAGE_TAG=${IMAGE_TAG:-latest}

echo "Deploying HybridPHP to namespace: $NAMESPACE"

# 应用配置
kubectl apply -f k8s/configmap.yaml -n $NAMESPACE
kubectl apply -f k8s/secret.yaml -n $NAMESPACE

# 部署应用
kubectl set image deployment/hybridphp-app \
  app=hybridphp/app:$IMAGE_TAG -n $NAMESPACE

# 等待部署完成
kubectl rollout status deployment/hybridphp-app -n $NAMESPACE

# 验证
kubectl get pods -l app=hybridphp -n $NAMESPACE

echo "Deployment completed!"
```

## 常用命令

```bash
# 部署应用
kubectl apply -f k8s/

# 查看 Pod 状态
kubectl get pods -l app=hybridphp

# 查看日志
kubectl logs -f deployment/hybridphp-app

# 进入 Pod
kubectl exec -it deployment/hybridphp-app -- sh

# 扩缩容
kubectl scale deployment hybridphp-app --replicas=5

# 回滚
kubectl rollout undo deployment/hybridphp-app

# 查看部署历史
kubectl rollout history deployment/hybridphp-app
```

## 下一步

- [Docker 部署](./DOCKER.md) - 容器化部署
- [监控告警](./MONITORING.md) - 监控系统
- [性能优化](./PERFORMANCE.md) - 性能调优
