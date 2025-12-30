#!/bin/sh

# HybridPHP Framework Health Check Script
# This script performs comprehensive health checks for containerized deployment

set -e

# Configuration
HEALTH_ENDPOINT="${HEALTH_ENDPOINT:-http://localhost:8080/api/v1/health}"
TIMEOUT="${TIMEOUT:-10}"
MAX_RETRIES="${MAX_RETRIES:-3}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if curl is available
if ! command -v curl >/dev/null 2>&1; then
    log_error "curl is not available"
    exit 1
fi

# Perform health check with retries
check_health() {
    local attempt=1
    
    while [ $attempt -le $MAX_RETRIES ]; do
        log_info "Health check attempt $attempt/$MAX_RETRIES"
        
        if curl -f -s --max-time $TIMEOUT "$HEALTH_ENDPOINT" >/dev/null 2>&1; then
            log_info "Health check passed"
            return 0
        fi
        
        if [ $attempt -lt $MAX_RETRIES ]; then
            log_warn "Health check failed, retrying in 2 seconds..."
            sleep 2
        fi
        
        attempt=$((attempt + 1))
    done
    
    log_error "Health check failed after $MAX_RETRIES attempts"
    return 1
}

# Additional checks for specific services
check_database() {
    if [ -n "$DB_HOST" ] && [ -n "$DB_PORT" ]; then
        log_info "Checking database connectivity..."
        if nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; then
            log_info "Database is reachable"
        else
            log_warn "Database is not reachable"
            return 1
        fi
    fi
}

check_redis() {
    if [ -n "$REDIS_HOST" ] && [ -n "$REDIS_PORT" ]; then
        log_info "Checking Redis connectivity..."
        if nc -z "$REDIS_HOST" "$REDIS_PORT" 2>/dev/null; then
            log_info "Redis is reachable"
        else
            log_warn "Redis is not reachable"
            return 1
        fi
    fi
}

# Main health check
main() {
    log_info "Starting health check for HybridPHP Framework"
    
    # Primary health check
    if ! check_health; then
        exit 1
    fi
    
    # Additional checks (non-critical)
    check_database || log_warn "Database check failed (non-critical)"
    check_redis || log_warn "Redis check failed (non-critical)"
    
    log_info "All health checks completed successfully"
    exit 0
}

# Run main function
main "$@"