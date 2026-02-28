<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PermissionSummaryResource;
use App\Http\Resources\Admin\RolePermissionSummaryResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = $this->resourcePayload(RolePermissionSummaryResource::collection(
            Role::query()
                ->with('permissions')
                ->withCount('users')
                ->orderBy('name')
                ->get()
        ));

        $permissions = $this->resourcePayload(PermissionSummaryResource::collection(
            Permission::query()
                ->orderBy('name')
                ->get()
        ));

        return Inertia::render('Admin/Permissions', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }
}
