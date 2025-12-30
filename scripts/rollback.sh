#!/bin/bash

set -e

# HybridPHP Rollback Script
# Provides quick rollback capabilities for failed deployments

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Default values
ENVIRONMENT="staging"
NAMESPACE=""
KUBECONFIG_FILE=""
ROLLBACK_REVISION=""
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

Rollback HybridPHP Framework deployment

OPTIONS:
    -e, --environment ENVIRONMENT    Target environment (staging|production) [default: staging]
    -r, --revision REVISION         Specific revision to rollback to (optional)
    -k, --kubeconfig FILE           Path to kubeconfig file
    -n, --namespace NAMESPACE       Kubernetes namespace
    -d, --dry-run                   Perform a dry run without making changes
    -v, --verbose                   Enable verbose output
    -h, --help                      Show this help message

EXAMPLES:
    $0 -e staging
    $0 -e production -r 5
    $0 -e production -d

EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -e|--environment)
            ENVIRONMENT="$2"
            shift 2
            ;;
        -r|--revision)
            ROLLBACK_REVISION="$2"
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

# Set namespace if not provided
if [[ -z "$NAMESPACE" ]]; then
    NAMESPACE="$ENVIRONMENT"
fi

# Set kubeconfig
if [[ -n "$KUBECONFIG_FILE" ]]; then
    export KUBECONFIG="$KUBECONFIG_FILE"
fi

log_info "Starting rollback with the following configuration:"
log_info "  Environment: $ENVIRONMENT"
log_info "  Namespace: $NAMESPACE"
log_info "  Revision: ${ROLLBACK_REVISION:-latest}"
log_info "  Dry Run: $DRY_RUN"

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check kubectl
    if ! command -v kubectl &> /dev/null; then
        log_error "kubectl is not installed or not in PATH"
        exit 1
    fi
    
    # Check cluster connectivity
    if ! kubectl cluster-info &> /dev/null; then
        log_error "Cannot connect to Kubernetes cluster"
        exit 1
    fi
    
    # Check namespace exists
    if ! kubectl get namespace "$NAMESPACE" &> /dev/null; then
        log_error "Namespace '$NAMESPACE' does not exist"
        exit 1
    fi
    
    log_success "Prerequisites check passed"
}

# Show deployment history
show_deployment_history() {
    log_info "Deployment history for hybridphp-$ENVIRONMENT:"
    kubectl rollout history deployment/hybridphp-$ENVIRONMENT -n "$NAMESPACE"
}

# Get current deployment status
get_current_status() {
    log_info "Current deployment status:"
    kubectl get deployment hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o wide
    
    log_info "Current pods:"
    kubectl get pods -n "$NAMESPACE" -l app=hybridphp,environment="$ENVIRONMENT"
}

# Perform rollback
perform_rollback() {
    local deployment_name="hybridphp-$ENVIRONMENT"
    
    log_info "Performing rollback for deployment: $deployment_name"
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN: Would rollback deployment"
        if [[ -n "$ROLLBACK_REVISION" ]]; then
            log_info "Would rollback to revision: $ROLLBACK_REVISION"
        else
            log_info "Would rollback to previous revision"
        fi
        return
    fi
    
    # Perform the rollback
    if [[ -n "$ROLLBACK_REVISION" ]]; then
        kubectl rollout undo deployment/"$deployment_name" -n "$NAMESPACE" --to-revision="$ROLLBACK_REVISION"
    else
        kubectl rollout undo deployment/"$deployment_name" -n "$NAMESPACE"
    fi
    
    # Wait for rollback to complete
    log_info "Waiting for rollback to complete..."
    kubectl rollout status deployment/"$deployment_name" -n "$NAMESPACE" --timeout=300s
    
    log_success "Rollback completed successfully"
}

# Verify rollback
verify_rollback() {
    log_info "Verifying rollback..."
    
    # Check deployment status
    local ready_replicas
    ready_replicas=$(kubectl get deployment hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o jsonpath='{.status.readyReplicas}')
    local desired_replicas
    desired_replicas=$(kubectl get deployment hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o jsonpath='{.spec.replicas}')
    
    if [[ "$ready_replicas" == "$desired_replicas" ]]; then
        log_success "All replicas are ready ($ready_replicas/$desired_replicas)"
    else
        log_error "Not all replicas are ready ($ready_replicas/$desired_replicas)"
        return 1
    fi
    
    # Run health checks
    if run_health_checks; then
        log_success "Health checks passed after rollback"
    else
        log_error "Health checks failed after rollback"
        return 1
    fi
}

# Run health checks
run_health_checks() {
    local service_name="hybridphp-$ENVIRONMENT"
    
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

# Handle blue-green rollback
rollback_blue_green() {
    log_info "Handling blue-green rollback..."
    
    # Get current active environment
    local current_env
    current_env=$(kubectl get service hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o jsonpath='{.spec.selector.version}' 2>/dev/null || echo "blue")
    
    local previous_env
    if [[ "$current_env" == "blue" ]]; then
        previous_env="green"
    else
        previous_env="blue"
    fi
    
    log_info "Current active environment: $current_env"
    log_info "Rolling back to: $previous_env"
    
    # Check if previous environment exists
    if ! kubectl get deployment hybridphp-$ENVIRONMENT-$previous_env -n "$NAMESPACE" &> /dev/null; then
        log_error "Previous environment deployment not found: hybridphp-$ENVIRONMENT-$previous_env"
        exit 1
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "DRY RUN: Would switch traffic to $previous_env environment"
        return
    fi
    
    # Switch traffic back to previous environment
    kubectl patch service hybridphp-$ENVIRONMENT -n "$NAMESPACE" -p "{\"spec\":{\"selector\":{\"version\":\"$previous_env\"}}}"
    
    log_success "Traffic switched back to $previous_env environment"
}

# Main rollback function
main() {
    check_prerequisites
    
    show_deployment_history
    get_current_status
    
    # Check if this is a blue-green deployment
    if kubectl get service hybridphp-$ENVIRONMENT -n "$NAMESPACE" -o jsonpath='{.spec.selector.version}' &> /dev/null; then
        rollback_blue_green
    else
        perform_rollback
    fi
    
    if [[ "$DRY_RUN" == "false" ]]; then
        verify_rollback
        
        log_success "Rollback completed successfully!"
        send_notification "success" "HybridPHP rollback completed successfully for $ENVIRONMENT"
    else
        log_success "Dry run completed successfully!"
    fi
}

# Trap errors and send notifications
trap 'log_error "Rollback failed"; send_notification "error" "HybridPHP rollback failed for $ENVIRONMENT"' ERR

# Run main function
main