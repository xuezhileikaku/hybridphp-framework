#!/bin/bash

set -e

# HybridPHP Deployment Script
# Supports multiple environments and deployment strategies

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Default values
ENVIRONMENT="staging"
DEPLOYMENT_TYPE="rolling"
IMAGE_TAG="latest"
NAMESPACE=""
KUBECONFIG_FILE=""
DRY_RUN=false
VERBOSE=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Usage function
usage() {
    cat << EOF
Usage: $0 [OPTIONS]

Deploy HybridPHP Framework to Kubernetes

OPTIONS:
    -e, --environment ENVIRONMENT    Target environment (staging|production) [default: staging]
    -t, --type TYPE                  Deployment type (rolling|blue-green|canary) [default: rolling]
    -i, --image-tag TAG             Docker image tag [default: latest]
    -k, --kubeconfig FILE           Path to kubeconfig file
    -n, --namespace NAMESPACE       Kubernetes namespace
    -d, --dry-run                   Perform a dry run without making changes
    -v, --verbose                   Enable verbose output
    -h, --help                      Show this help message

EXAMPLES:
    $0 -e staging -t rolling
    $0 -e production -t blue-green -i v1.2.3
    $0 -e production -t canary -i v1.2.3 -d

EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -e|--environment)
            ENVIRONMENT="$2"
            shift 2
            ;;
        -t|--type)
            DEPLOYMENT_TYPE="$2"
            shift 2
            ;;
        -i|--image-tag)
            IMAGE_TAG="$2"
            shift 2
            ;;
        -k|--kubeconfig)
            KUBECONFIG_FILE="$2"
            shift 2
            ;;
        -n|--namespace)
            NAMESPACE="$2"
            shift 2
            ;;
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Validate environment
if [[ ! "$ENVIRONMENT" =~ ^(staging|production)$ ]]; then
    log_error "Invalid environment: $ENVIRONMENT. Must be 'staging' or 'production'"
    exit 1
fi

# Validate deployment type
if [[ ! "$DEPLOYMENT_TYPE" =~ ^(rolling|blue-green|canary)$ ]]; then
    log_error "Invalid deployment type: $DEPLOYMENT_TYPE. Must be 'rolling', 'blue-green', or 'canary'"
    exit 1
fi

# Set namespace if not provided
if [[ -z "$NAMESPACE" ]]; then
    NAMESPACE="$ENVIRONMENT"
fi

# Set kubeconfig
if [[ -n "$KUBECONFIG_FILE" ]]; then
    export KUBECONFIG="$KUBECONFIG_FILE"
fi

log_info "Starting deployment with the following configuration:"
log_info "  Environment: $ENVIRONMENT"
log_info "  Deployment Type: $DEPLOYMENT_TYPE"
log_info "  Image Tag: $IMAGE_TAG"
log_info "  Namespace: $NAMESPACE"
log_info "  Dry Run: $DRY_RUN"

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check kubectl
    if ! command -v kubectl &> /dev/null; then
        log_error "kubectl is not installed or not in PATH"
        exit 1
    fi
    
    # Check envsubst
    if ! command -v envsubst &> /dev/null; then
        log_error "envsubst is not installed or not in PATH"
        exit 1
    fi
    
    # Check cluster connectivity
    if ! kubectl cluster-info &> /dev/null; then
        log_error "Cannot connect to Kubernetes cluster"
        exit 1
    fi
    
    # Check namespace exists
    if ! kubectl get namespace "$NAMESPACE" &> /dev/null; then
        log_warning "Namespace '$NAMESPACE' does not exist. Creating..."
        if [[ "$DRY_RUN" == "false" ]]; then
            kubectl create namespace "$NAMESPACE"
        fi
    fi
    
    log_success "Prerequisites check passed"
}

# Deploy using rolling update strategy
deploy_rolling() {
    log_info "Deploying using rolling update strategy..."
    
    export IMAGE_TAG
    export ENVIRONMENT
    
    local k8s_dir="$PROJECT_ROOT/k8s/$ENVIRONMENT"
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN: Would apply the following manifests:"
        envsubst < "$k8s_dir/deployment.yaml" | kubectl apply --dry-run=client -f -
        envsubst < "$k8s_dir/service.yaml" | kubectl apply --dry-run=client -f -
        envsubst < "$k8s_dir/ingress.yaml" | kubectl apply --dry-run=client -f -
    else
        envsubst < "$k8s_dir/deployment.yaml" | kubectl apply -f -
        envsubst < "$k8s_dir/service.yaml" | kubectl apply -f -
        envsubst < "$k8s_dir/ingress.yaml" | kubectl apply -f -
        
        # Wait for rollout to complete
        kubectl rollout status deployment/hybridphp-$ENVIRONMENT -n "$NAMESPACE" --timeout=600s
    fi
}

# Deploy using blue-green strategy
deploy_blue_green() {
    log_info "Deploying using blue-green strategy..."
    
    export IMAGE_TAG
    export ENVIRONMENT
    
    # Determine current and next environments
    local current_env
    current_env=$(kubectl get service hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o jsonpath='{.spec.selector.version}' 2>/dev/null || echo "blue")
    
    local next_env
    if [[ "$current_env" == "blue" ]]; then
        next_env="green"
    else
        next_env="blue"
    fi
    
    log_info "Current environment: $current_env"
    log_info "Deploying to: $next_env"
    
    export COLOR="$next_env"
    
    local k8s_dir="$PROJECT_ROOT/k8s/$ENVIRONMENT"
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN: Would deploy to $next_env environment"
        envsubst < "$k8s_dir/deployment-blue-green.yaml" | kubectl apply --dry-run=client -f -
    else
        # Deploy to next environment
        envsubst < "$k8s_dir/deployment-blue-green.yaml" | kubectl apply -f -
        
        # Wait for deployment to be ready
        kubectl rollout status deployment/hybridphp-$ENVIRONMENT-$next_env -n "$NAMESPACE" --timeout=600s
        
        # Run health checks
        log_info "Running health checks on new deployment..."
        if run_health_checks "$next_env"; then
            # Switch traffic to new environment
            log_info "Switching traffic to $next_env environment..."
            kubectl patch service hybridphp-$ENVIRONMENT -n "$NAMESPACE" -p "{\"spec\":{\"selector\":{\"version\":\"$next_env\"}}}"
            log_success "Traffic switched to $next_env environment"
            
            # Schedule cleanup of old environment
            log_info "Old environment ($current_env) kept for potential rollback"
        else
            log_error "Health checks failed. Deployment aborted."
            exit 1
        fi
    fi
}

# Deploy using canary strategy
deploy_canary() {
    log_info "Deploying using canary strategy..."
    log_warning "Canary deployment is not fully implemented yet"
    
    # TODO: Implement canary deployment
    # This would involve:
    # 1. Deploy canary version alongside current version
    # 2. Route small percentage of traffic to canary
    # 3. Monitor metrics and gradually increase traffic
    # 4. Complete rollout or rollback based on metrics
    
    exit 1
}

# Run health checks
run_health_checks() {
    local version="$1"
    local service_name="hybridphp-$ENVIRONMENT"
    
    if [[ -n "$version" ]]; then
        service_name="hybridphp-$ENVIRONMENT-$version"
    fi
    
    log_info "Running health checks against $service_name..."
    
    # Port forward to service
    kubectl port-forward service/"$service_name" 8080:80 -n "$NAMESPACE" &
    local port_forward_pid=$!
    
    # Wait for port forward to be ready
    sleep 10
    
    # Run health checks
    local health_check_passed=true
    
    # Basic health check
    if ! curl -f http://localhost:8080/health &> /dev/null; then
        log_error "Basic health check failed"
        health_check_passed=false
    fi
    
    # API status check
    if ! curl -f http://localhost:8080/api/v1/status &> /dev/null; then
        log_error "API status check failed"
        health_check_passed=false
    fi
    
    # Cleanup port forward
    kill $port_forward_pid 2>/dev/null || true
    
    if [[ "$health_check_passed" == "true" ]]; then
        log_success "Health checks passed"
        return 0
    else
        log_error "Health checks failed"
        return 1
    fi
}

# Rollback deployment
rollback_deployment() {
    log_warning "Rolling back deployment..."
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN: Would rollback deployment"
    else
        kubectl rollout undo deployment/hybridphp-$ENVIRONMENT -n "$NAMESPACE"
        kubectl rollout status deployment/hybridphp-$ENVIRONMENT -n "$NAMESPACE" --timeout=300s
        log_success "Rollback completed"
    fi
}

# Send notification
send_notification() {
    local status="$1"
    local message="$2"
    
    if [[ -n "$SLACK_WEBHOOK_URL" ]]; then
        local emoji
        if [[ "$status" == "success" ]]; then
            emoji="✅"
        elif [[ "$status" == "warning" ]]; then
            emoji="⚠️"
        else
            emoji="❌"
        fi
        
        local payload="{\"text\":\"$emoji $message\"}"
        
        curl -X POST -H 'Content-type: application/json' \
            --data "$payload" \
            "$SLACK_WEBHOOK_URL" &> /dev/null || true
    fi
}

# Main deployment function
main() {
    check_prerequisites
    
    case "$DEPLOYMENT_TYPE" in
        rolling)
            deploy_rolling
            ;;
        blue-green)
            deploy_blue_green
            ;;
        canary)
            deploy_canary
            ;;
    esac
    
    if [[ "$DRY_RUN" == "false" ]]; then
        # Run final health checks
        if run_health_checks; then
            log_success "Deployment completed successfully!"
            send_notification "success" "HybridPHP deployed successfully to $ENVIRONMENT using $DEPLOYMENT_TYPE strategy"
        else
            log_error "Post-deployment health checks failed"
            send_notification "error" "HybridPHP deployment to $ENVIRONMENT failed health checks"
            
            if [[ "$DEPLOYMENT_TYPE" == "rolling" ]]; then
                rollback_deployment
            fi
            
            exit 1
        fi
    else
        log_success "Dry run completed successfully!"
    fi
}

# Trap errors and send notifications
trap 'log_error "Deployment failed"; send_notification "error" "HybridPHP deployment to $ENVIRONMENT failed"' ERR

# Run main function
main