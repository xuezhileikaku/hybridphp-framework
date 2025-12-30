<?php

use HybridPHP\Core\Database\Seeder\Seeder;

/**
 * Authentication system seeder
 */
class AuthSeeder extends Seeder
{
    public function run(): void
    {
        // Create basic roles
        $this->createRoles();
        
        // Create basic permissions
        $this->createPermissions();
        
        // Assign permissions to roles
        $this->assignPermissionsToRoles();
        
        // Create admin user
        $this->createAdminUser();
    }

    private function createRoles(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'description' => 'System Administrator',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'moderator',
                'description' => 'Content Moderator',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'user',
                'description' => 'Regular User',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'guest',
                'description' => 'Guest User',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($roles as $role) {
            $this->db->execute(
                "INSERT INTO roles (name, description, created_at) VALUES (?, ?, ?)",
                [$role['name'], $role['description'], $role['created_at']]
            );
        }
    }

    private function createPermissions(): void
    {
        $permissions = [
            // User management
            ['name' => 'users.view', 'description' => 'View users', 'resource' => 'users', 'action' => 'view'],
            ['name' => 'users.create', 'description' => 'Create users', 'resource' => 'users', 'action' => 'create'],
            ['name' => 'users.update', 'description' => 'Update users', 'resource' => 'users', 'action' => 'update'],
            ['name' => 'users.delete', 'description' => 'Delete users', 'resource' => 'users', 'action' => 'delete'],
            
            // Role management
            ['name' => 'roles.view', 'description' => 'View roles', 'resource' => 'roles', 'action' => 'view'],
            ['name' => 'roles.create', 'description' => 'Create roles', 'resource' => 'roles', 'action' => 'create'],
            ['name' => 'roles.update', 'description' => 'Update roles', 'resource' => 'roles', 'action' => 'update'],
            ['name' => 'roles.delete', 'description' => 'Delete roles', 'resource' => 'roles', 'action' => 'delete'],
            
            // Permission management
            ['name' => 'permissions.view', 'description' => 'View permissions', 'resource' => 'permissions', 'action' => 'view'],
            ['name' => 'permissions.create', 'description' => 'Create permissions', 'resource' => 'permissions', 'action' => 'create'],
            ['name' => 'permissions.update', 'description' => 'Update permissions', 'resource' => 'permissions', 'action' => 'update'],
            ['name' => 'permissions.delete', 'description' => 'Delete permissions', 'resource' => 'permissions', 'action' => 'delete'],
            
            // Content management
            ['name' => 'posts.view', 'description' => 'View posts', 'resource' => 'posts', 'action' => 'view'],
            ['name' => 'posts.create', 'description' => 'Create posts', 'resource' => 'posts', 'action' => 'create'],
            ['name' => 'posts.update', 'description' => 'Update posts', 'resource' => 'posts', 'action' => 'update'],
            ['name' => 'posts.delete', 'description' => 'Delete posts', 'resource' => 'posts', 'action' => 'delete'],
            ['name' => 'posts.publish', 'description' => 'Publish posts', 'resource' => 'posts', 'action' => 'publish'],
            
            // System management
            ['name' => 'system.settings', 'description' => 'Manage system settings', 'resource' => 'system', 'action' => 'settings'],
            ['name' => 'system.logs', 'description' => 'View system logs', 'resource' => 'system', 'action' => 'logs'],
            ['name' => 'system.maintenance', 'description' => 'System maintenance', 'resource' => 'system', 'action' => 'maintenance'],
            
            // Profile management
            ['name' => 'profile.view', 'description' => 'View own profile', 'resource' => 'profile', 'action' => 'view'],
            ['name' => 'profile.update', 'description' => 'Update own profile', 'resource' => 'profile', 'action' => 'update'],
        ];

        foreach ($permissions as $permission) {
            $this->db->execute(
                "INSERT INTO permissions (name, description, resource, action, created_at) VALUES (?, ?, ?, ?, ?)",
                [
                    $permission['name'],
                    $permission['description'],
                    $permission['resource'],
                    $permission['action'],
                    date('Y-m-d H:i:s')
                ]
            );
        }
    }

    private function assignPermissionsToRoles(): void
    {
        // Get role IDs
        $adminRole = $this->db->query("SELECT id FROM roles WHERE name = 'admin'")[0];
        $moderatorRole = $this->db->query("SELECT id FROM roles WHERE name = 'moderator'")[0];
        $userRole = $this->db->query("SELECT id FROM roles WHERE name = 'user'")[0];

        // Get all permissions
        $permissions = $this->db->query("SELECT id, name FROM permissions");
        $permissionMap = [];
        foreach ($permissions as $permission) {
            $permissionMap[$permission['name']] = $permission['id'];
        }

        // Admin gets all permissions
        foreach ($permissionMap as $permissionId) {
            $this->db->execute(
                "INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, ?)",
                [$adminRole['id'], $permissionId, date('Y-m-d H:i:s')]
            );
        }

        // Moderator permissions
        $moderatorPermissions = [
            'users.view', 'posts.view', 'posts.create', 'posts.update', 
            'posts.delete', 'posts.publish', 'profile.view', 'profile.update'
        ];
        foreach ($moderatorPermissions as $permissionName) {
            if (isset($permissionMap[$permissionName])) {
                $this->db->execute(
                    "INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, ?)",
                    [$moderatorRole['id'], $permissionMap[$permissionName], date('Y-m-d H:i:s')]
                );
            }
        }

        // User permissions
        $userPermissions = [
            'posts.view', 'posts.create', 'profile.view', 'profile.update'
        ];
        foreach ($userPermissions as $permissionName) {
            if (isset($permissionMap[$permissionName])) {
                $this->db->execute(
                    "INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, ?)",
                    [$userRole['id'], $permissionMap[$permissionName], date('Y-m-d H:i:s')]
                );
            }
        }
    }

    private function createAdminUser(): void
    {
        // Create admin user
        $adminUserId = $this->db->execute(
            "INSERT INTO users (username, email, password, status, created_at) VALUES (?, ?, ?, ?, ?)",
            [
                'admin',
                'admin@hybridphp.com',
                password_hash('admin123', PASSWORD_DEFAULT),
                1,
                date('Y-m-d H:i:s')
            ]
        );

        // Assign admin role
        $adminRole = $this->db->query("SELECT id FROM roles WHERE name = 'admin'")[0];
        $this->db->execute(
            "INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, ?)",
            [$adminUserId, $adminRole['id'], date('Y-m-d H:i:s')]
        );

        // Create regular test user
        $testUserId = $this->db->execute(
            "INSERT INTO users (username, email, password, status, created_at) VALUES (?, ?, ?, ?, ?)",
            [
                'testuser',
                'test@hybridphp.com',
                password_hash('test123', PASSWORD_DEFAULT),
                1,
                date('Y-m-d H:i:s')
            ]
        );

        // Assign user role
        $userRole = $this->db->query("SELECT id FROM roles WHERE name = 'user'")[0];
        $this->db->execute(
            "INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, ?)",
            [$testUserId, $userRole['id'], date('Y-m-d H:i:s')]
        );
    }
}