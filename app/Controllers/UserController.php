<?php

declare(strict_types=1);

namespace App\Controllers;

use Amp\Future;
use HybridPHP\Core\Controller;
use HybridPHP\Core\Auth\User;
use HybridPHP\Core\Auth\RBAC\RBACManager;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use App\Models\User as UserModel;
use App\Models\Role;
use App\Models\Permission;
use function Amp\async;

/**
 * User management controller
 * 
 * Updated for AMPHP v3 - using ->await() instead of yield
 */
class UserController extends Controller
{
    private User $user;
    private RBACManager $rbacManager;

    public function __construct(User $user, RBACManager $rbacManager)
    {
        $this->user = $user;
        $this->rbacManager = $rbacManager;
    }

    /**
     * List users
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function index(Request $request): Future
    {
        return async(function () use ($request) {
            $page = (int) ($request->getQueryParam('page') ?? 1);
            $limit = (int) ($request->getQueryParam('limit') ?? 20);
            $offset = ($page - 1) * $limit;

            $users = UserModel::find()
                ->limit($limit)
                ->offset($offset)
                ->all()->await();

            $total = UserModel::find()->count()->await();

            return $this->jsonResponse([
                'success' => true,
                'data' => array_map(fn($user) => $user->toArray(), $users),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        });
    }

    /**
     * Get user by ID
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function show(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $user = UserModel::findOne($id)->await();

            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get user roles and permissions
            $roles = $this->rbacManager->getUserRoles($user)->await();
            $permissions = $this->rbacManager->getUserPermissions($user)->await();

            $userData = $user->toArray();
            $userData['roles'] = $roles;
            $userData['permissions'] = $permissions;

            return $this->jsonResponse([
                'success' => true,
                'data' => $userData
            ]);
        });
    }

    /**
     * Create new user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function create(Request $request): Future
    {
        return async(function () use ($request) {
            $data = $request->getParsedBody();

            $user = new UserModel();
            $user->setAttributes($data);

            if ($user->save()->await()) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => $user->toArray(),
                    'message' => 'User created successfully'
                ], 201);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create user',
                'errors' => $user->getErrors()
            ], 400);
        });
    }

    /**
     * Update user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function update(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $data = $request->getParsedBody();

            $user = UserModel::findOne($id)->await();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $user->setAttributes($data);

            if ($user->save()->await()) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => $user->toArray(),
                    'message' => 'User updated successfully'
                ]);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update user',
                'errors' => $user->getErrors()
            ], 400);
        });
    }

    /**
     * Delete user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function delete(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $user = UserModel::findOne($id)->await();

            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->delete()->await()) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to delete user'
            ], 400);
        });
    }

    /**
     * Assign role to user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function assignRole(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $data = $request->getParsedBody();
            $roleName = $data['role'] ?? '';

            if (empty($roleName)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Role name is required'
                ], 400);
            }

            $user = UserModel::findOne($id)->await();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $success = $this->rbacManager->assignRole($user, $roleName)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Role assigned successfully' : 'Failed to assign role'
            ]);
        });
    }

    /**
     * Remove role from user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function removeRole(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $data = $request->getParsedBody();
            $roleName = $data['role'] ?? '';

            if (empty($roleName)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Role name is required'
                ], 400);
            }

            $user = UserModel::findOne($id)->await();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $success = $this->rbacManager->removeRole($user, $roleName)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Role removed successfully' : 'Failed to remove role'
            ]);
        });
    }

    /**
     * Grant permission to user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function grantPermission(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $data = $request->getParsedBody();
            $permissionName = $data['permission'] ?? '';

            if (empty($permissionName)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Permission name is required'
                ], 400);
            }

            $user = UserModel::findOne($id)->await();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $success = $this->rbacManager->grantPermission($user, $permissionName)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Permission granted successfully' : 'Failed to grant permission'
            ]);
        });
    }

    /**
     * Revoke permission from user
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function revokePermission(Request $request): Future
    {
        return async(function () use ($request) {
            $id = $request->getAttribute('id');
            $data = $request->getParsedBody();
            $permissionName = $data['permission'] ?? '';

            if (empty($permissionName)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Permission name is required'
                ], 400);
            }

            $user = UserModel::findOne($id)->await();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $success = $this->rbacManager->revokePermission($user, $permissionName)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Permission revoked successfully' : 'Failed to revoke permission'
            ]);
        });
    }
}
