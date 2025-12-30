#!/bin/bash

# HybridPHP Framework Docker Deployment Script
# This script manages Docker Compose deployments for different environments

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${PROJECT_ROOT}/.env"

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

log_debug() {
    echo -e "${BLUE}[DEBUG]${NC} $1"
}

show_usage() {
    cat << EOF
HybridPHP Framework Docker Deployment Script

Usage: $0 [COMMAND] [OPTIONS]

Commands:
    dev         Start development environment
    prod        Start production environment
    stop        Stop all services
    restart     Restart services
    logs        Show service logs
    status      Show service status
    clean       Clean up containers and volumes
    build       Build Docker images
    health      Check service health
    backup      Backup database and data
    restore     Restore from backup

Options:
    -e, --env FILE      Environment file (default: .env)
    -f, --file FILE     Docker compose file
    -s, --service NAME  Target specific service
    -v, --verbose       Verbose output
    -h, --help          Show this help

Examples:
    $0 dev                          # Start development environment
    $0 prod -e .env.production      # Start production with custom env
    $0 logs -s hybridphp           # Show logs for hybridphp service
    $0 backup                       # Backup database
    $0 clean                        # Clean up everything

EOF
}

check_prerequisites() {
    log_info "Checking prerequisites..."
    
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed"
        exit 1
    fi
    
    if ! command -v docker-compose &> /dev/null; then
        log_error "Docker Compose is not installed"
        exit 1
    fi
    
    # Check if Docker daemon is running
    if ! docker info &> /dev/null; then
        log_error "Docker daemon is not running"
        exit 1
    fi
    
    log_info "Prerequisites check passed"
}

load_environment() {
    local env_file="${1:-$ENV_FILE}"
    
    if [[ -f "$env_file" ]]; then
        log_info "Loading environment from: $env_file"
        set -a
        source "$env_file"
        set +a
    else
        log_warn "Environment file not found: $env_file"
        log_info "Using default environment variables"
    fi
}

build_images() {
    log_info "Building Docker images..."
    
    cd "$PROJECT_ROOT"
    
    # Build development image
    log_info "Building development image..."
    docker build -t hybridphp:dev --target development -f docker/Dockerfile .
    
    # Build production image
    log_info "Building production image..."
    docker build -t hybridphp:prod --target production -f docker/Dockerfile .
    
    log_info "Docker images built successfully"
}

start_development() {
    log_info "Starting development environment..."
    
    cd "$PROJECT_ROOT"
    load_environment
    
    # Create required directories
    mkdir -p storage/{logs,cache,sessions,ssl}
    
    # Set permissions
    chmod -R 755 storage
    
    # Start services
    docker-compose up -d
    
    # Wait for services to be ready
    wait_for_services
    
    show_service_urls "development"
}

start_production() {
    log_info "Starting production environment..."
    
    cd "$PROJECT_ROOT"
    load_environment "${ENV_FILE}.production"
    
    # Create required directories
    mkdir -p storage/{logs,cache,sessions,ssl}
    
    # Set proper permissions for production
    chmod -R 755 storage
    
    # Start services
    docker-compose -f docker-compose.prod.yml up -d
    
    # Wait for services to be ready
    wait_for_services
    
    show_service_urls "production"
}

wait_for_services() {
    log_info "Waiting for services to be ready..."
    
    local max_attempts=30
    local attempt=1
    
    while [[ $attempt -le $max_attempts ]]; do
        if check_service_health; then
            log_info "All services are ready!"
            return 0
        fi
        
        log_info "Attempt $attempt/$max_attempts - Services not ready yet, waiting..."
        sleep 10
        ((attempt++))
    done
    
    log_error "Services failed to start within expected time"
    return 1
}

check_service_health() {
    local healthy=true
    
    # Check MySQL
    if ! docker-compose exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null; then
        log_debug "MySQL not ready"
        healthy=false
    fi
    
    # Check Redis
    if ! docker-compose exec -T redis redis-cli ping 2>/dev/null | grep -q PONG; then
        log_debug "Redis not ready"
        healthy=false
    fi
    
    # Check HybridPHP application
    if ! curl -f http://localhost:8080/api/v1/health &>/dev/null; then
        log_debug "HybridPHP application not ready"
        healthy=false
    fi
    
    $healthy
}

show_service_urls() {
    local env="$1"
    
    log_info "Service URLs for $env environment:"
    echo "  Application:     http://localhost:8080"
    echo "  WebSocket:       ws://localhost:2346"
    echo "  Nginx Proxy:     http://localhost"
    echo "  Health Check:    http://localhost:8080/api/v1/health"
    echo "  Metrics:         http://localhost:8080/api/v1/metrics"
    
    if [[ "$env" == "production" ]]; then
        echo "  Prometheus:      http://localhost:9090"
        echo "  Grafana:         http://localhost:3000 (admin/admin)"
        echo "  Kibana:          http://localhost:5601"
    fi
}

stop_services() {
    log_info "Stopping services..."
    
    cd "$PROJECT_ROOT"
    
    # Stop development environment
    if docker-compose ps -q &>/dev/null; then
        docker-compose down
    fi
    
    # Stop production environment
    if docker-compose -f docker-compose.prod.yml ps -q &>/dev/null; then
        docker-compose -f docker-compose.prod.yml down
    fi
    
    log_info "Services stopped"
}

restart_services() {
    log_info "Restarting services..."
    stop_services
    sleep 5
    
    # Determine which environment to restart based on running containers
    if docker ps --format "table {{.Names}}" | grep -q "_prod"; then
        start_production
    else
        start_development
    fi
}

show_logs() {
    local service="$1"
    
    cd "$PROJECT_ROOT"
    
    if [[ -n "$service" ]]; then
        log_info "Showing logs for service: $service"
        docker-compose logs -f "$service"
    else
        log_info "Showing logs for all services"
        docker-compose logs -f
    fi
}

show_status() {
    log_info "Service status:"
    
    cd "$PROJECT_ROOT"
    
    echo "=== Docker Containers ==="
    docker-compose ps
    
    echo -e "\n=== Service Health ==="
    if check_service_health; then
        echo -e "${GREEN}✓ All services are healthy${NC}"
    else
        echo -e "${RED}✗ Some services are unhealthy${NC}"
    fi
    
    echo -e "\n=== Resource Usage ==="
    docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}"
}

clean_up() {
    log_warn "This will remove all containers, networks, and volumes. Are you sure? (y/N)"
    read -r response
    
    if [[ "$response" =~ ^[Yy]$ ]]; then
        log_info "Cleaning up Docker resources..."
        
        cd "$PROJECT_ROOT"
        
        # Stop and remove containers
        docker-compose down -v --remove-orphans
        docker-compose -f docker-compose.prod.yml down -v --remove-orphans
        
        # Remove images
        docker rmi hybridphp:dev hybridphp:prod 2>/dev/null || true
        
        # Clean up unused resources
        docker system prune -f
        
        log_info "Cleanup completed"
    else
        log_info "Cleanup cancelled"
    fi
}

backup_data() {
    log_info "Creating backup..."
    
    local backup_dir="backups/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backup_dir"
    
    cd "$PROJECT_ROOT"
    
    # Backup database
    log_info "Backing up MySQL database..."
    docker-compose exec -T mysql mysqldump -u root -proot --all-databases > "$backup_dir/mysql_backup.sql"
    
    # Backup Redis data
    log_info "Backing up Redis data..."
    docker-compose exec -T redis redis-cli BGSAVE
    docker cp "$(docker-compose ps -q redis):/data/dump.rdb" "$backup_dir/redis_dump.rdb"
    
    # Backup application data
    log_info "Backing up application data..."
    tar -czf "$backup_dir/storage_backup.tar.gz" storage/
    
    log_info "Backup completed: $backup_dir"
}

restore_data() {
    local backup_dir="$1"
    
    if [[ -z "$backup_dir" || ! -d "$backup_dir" ]]; then
        log_error "Please specify a valid backup directory"
        exit 1
    fi
    
    log_warn "This will restore data from: $backup_dir. Continue? (y/N)"
    read -r response
    
    if [[ "$response" =~ ^[Yy]$ ]]; then
        log_info "Restoring data..."
        
        cd "$PROJECT_ROOT"
        
        # Restore database
        if [[ -f "$backup_dir/mysql_backup.sql" ]]; then
            log_info "Restoring MySQL database..."
            docker-compose exec -T mysql mysql -u root -proot < "$backup_dir/mysql_backup.sql"
        fi
        
        # Restore Redis data
        if [[ -f "$backup_dir/redis_dump.rdb" ]]; then
            log_info "Restoring Redis data..."
            docker-compose stop redis
            docker cp "$backup_dir/redis_dump.rdb" "$(docker-compose ps -q redis):/data/dump.rdb"
            docker-compose start redis
        fi
        
        # Restore application data
        if [[ -f "$backup_dir/storage_backup.tar.gz" ]]; then
            log_info "Restoring application data..."
            tar -xzf "$backup_dir/storage_backup.tar.gz"
        fi
        
        log_info "Restore completed"
    else
        log_info "Restore cancelled"
    fi
}

# Parse command line arguments
COMMAND=""
SERVICE=""
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        dev|prod|stop|restart|logs|status|clean|build|health|backup|restore)
            COMMAND="$1"
            shift
            ;;
        -e|--env)
            ENV_FILE="$2"
            shift 2
            ;;
        -f|--file)
            COMPOSE_FILE="$2"
            shift 2
            ;;
        -s|--service)
            SERVICE="$2"
            shift 2
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            show_usage
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            show_usage
            exit 1
            ;;
    esac
done

# Enable verbose mode if requested
if [[ "$VERBOSE" == true ]]; then
    set -x
fi

# Main execution
case "$COMMAND" in
    "dev")
        check_prerequisites
        start_development
        ;;
    "prod")
        check_prerequisites
        start_production
        ;;
    "stop")
        stop_services
        ;;
    "restart")
        check_prerequisites
        restart_services
        ;;
    "logs")
        show_logs "$SERVICE"
        ;;
    "status")
        show_status
        ;;
    "clean")
        clean_up
        ;;
    "build")
        check_prerequisites
        build_images
        ;;
    "health")
        if check_service_health; then
            log_info "All services are healthy"
            exit 0
        else
            log_error "Some services are unhealthy"
            exit 1
        fi
        ;;
    "backup")
        backup_data
        ;;
    "restore")
        restore_data "$2"
        ;;
    "")
        log_error "No command specified"
        show_usage
        exit 1
        ;;
    *)
        log_error "Unknown command: $COMMAND"
        show_usage
        exit 1
        ;;
esac