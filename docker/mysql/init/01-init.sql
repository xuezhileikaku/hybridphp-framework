-- HybridPHP Framework Database Initialization
-- This script sets up the initial database structure and configuration

-- Create additional databases if needed
CREATE DATABASE IF NOT EXISTS `hybridphp_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions
GRANT ALL PRIVILEGES ON `hybridphp`.* TO 'hybridphp'@'%';
GRANT ALL PRIVILEGES ON `hybridphp_test`.* TO 'hybridphp'@'%';

-- Create monitoring user for health checks
CREATE USER IF NOT EXISTS 'monitor'@'%' IDENTIFIED BY 'monitor';
GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'monitor'@'%';

-- Optimize MySQL settings for containerized environment
SET GLOBAL innodb_buffer_pool_size = 134217728; -- 128MB
SET GLOBAL innodb_log_file_size = 50331648; -- 48MB
SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL sync_binlog = 0;
SET GLOBAL innodb_flush_method = O_DIRECT;

-- Create performance schema tables for monitoring
USE performance_schema;

-- Flush privileges
FLUSH PRIVILEGES;