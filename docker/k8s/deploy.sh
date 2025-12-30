#!/bin/bash

# HybridPHP Kubernetes Deployment Script
# This script deploys the HybridPHP application to Kubernetes

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
NAMESPACE="hybridphp"
DOCKER_REGISTRY="${DOCKER_REGISTRY:-localhost:5000}"
IMAGE_TAG="${IMAGE_TAG:-latest}"

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_prerequisites() {
    log_info "Checking prerequisites..."
    
    if ! command -v kubectl &> /dev/null; then
        log_error "kubectl is not installed"
        exit 1
    fi
    
    if ! command -v docker &> /dev/null; then
        log_error "docker is not installed"
        exit 1
    fi
    
    # Check if kubectl can connect to cluster
    if ! kubectl cluster-info &> /dev/null; then
        log_error "Cannot connect to Kubernetes cluster"
        exit 1
    fi
    
    log_info "Prerequisites check passed"
}

build_and_push_image() {
    log_info "Building and pushing Docker image..."
    
    # Build the image
    docker build -t hybridphp:${IMAGE_TAG} -f docker/Dockerfile --target production .
    
    # Tag for registry
    docker tag hybridphp:${IMAGE_TAG} ${DOCKER_REGISTRY}/hybridphp:${IMAGE_TAG}
    
    # Push to registry
    docker push ${DOCKER_REGISTRY}/hybridphp:${IMAGE_TAG}
    
    log_info "Docker image built and pushed successfully"
}

create_namespace() {
    log_info "Creating namespace..."
    kubectl apply -f docker/k8s/namespace.yaml
}

deploy_secrets() {
    log_info "Deploying secrets..."
    kubectl apply -f docker/k8s/secrets.yaml
}

deploy_configmaps() {
    log_info "Deploying config maps..."
    kubectl apply -f docker/k8s/configmap.yaml
}

deploy_database() {
    log_info "Deploying MySQL database..."
    kubectl apply -f docker/k8s/mysql-deployment.yaml
    
    log_info "Waiting for MySQL to be ready..."
    kubectl wait --for=condition=ready pod -l app=mysql -n ${NAMESPACE} --timeout=300s
}

deploy_redis() {
    log_info "Deploying Redis cache..."
    kubectl apply -f docker/k8s/redis-deployment.yaml
    
    log_info "Waiting for Redis to be ready..."
    kubectl wait --for=condition=ready pod -l app=redis -n ${NAMESPACE} --timeout=300s
}

deploy_application() {
    log_info "Deploying HybridPHP application..."
    
    # Update image in deployment
    sed -i "s|image: hybridphp:latest|image: ${DOCKER_REGISTRY}/hybridphp:${IMAGE_TAG}|g" docker/k8s/hybridphp-deployment.yaml
    
    kubectl apply -f docker/k8s/hybridphp-deployment.yaml
    
    log_info "Waiting for HybridPHP to be ready..."
    kubectl wait --for=condition=ready pod -l app=hybridphp -n ${NAMESPACE} --timeout=300s
}

deploy_nginx() {
    log_info "Deploying Nginx reverse proxy..."
    kubectl apply -f docker/k8s/nginx-deployment.yaml
    
    log_info "Waiting for Nginx to be ready..."
    kubectl wait --for=condition=ready pod -l app=nginx -n ${NAMESPACE} --timeout=300s
}

deploy_hpa() {
    log_info "Deploying Horizontal Pod Autoscaler..."
    kubectl apply -f docker/k8s/hpa.yaml
}

deploy_ingress() {
    log_info "Deploying Ingress..."
    kubectl apply -f docker/k8s/ingress.yaml
}

deploy_monitoring() {
    log_info "Deploying monitoring configuration..."
    kubectl apply -f docker/k8s/monitoring.yaml
}

show_status() {
    log_info "Deployment status:"
    kubectl get pods -n ${NAMESPACE}
    kubectl get services -n ${NAMESPACE}
    kubectl get ingress -n ${NAMESPACE}
    
    log_info "Application URLs:"
    INGRESS_IP=$(kubectl get ingress hybridphp-ingress -n ${NAMESPACE} -o jsonpath='{.status.loadBalancer.ingress[0].ip}')
    if [ -n "$INGRESS_IP" ]; then
        echo "Application: http://${INGRESS_IP}"
        echo "API: http://${INGRESS_IP}/api"
        echo "Health Check: http://${INGRESS_IP}/health"
    else
        log_warn "Ingress IP not yet assigned. Check status with: kubectl get ingress -n ${NAMESPACE}"
    fi
}

# Main deployment flow
main() {
    log_info "Starting HybridPHP Kubernetes deployment..."
    
    check_prerequisites
    build_and_push_image
    create_namespace
    deploy_secrets
    deploy_configmaps
    deploy_database
    deploy_redis
    deploy_application
    deploy_nginx
    deploy_hpa
    deploy_ingress
    deploy_monitoring
    show_status
    
    log_info "Deployment completed successfully!"
}

# Handle script arguments
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "clean")
        log_info "Cleaning up deployment..."
        kubectl delete namespace ${NAMESPACE} --ignore-not-found=true
        log_info "Cleanup completed"
        ;;
    "status")
        show_status
        ;;
    *)
        echo "Usage: $0 [deploy|clean|status]"
        exit 1
        ;;
esac