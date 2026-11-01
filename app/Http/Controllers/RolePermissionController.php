<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    public function index()
    {
        return view('roles.index', [
            'roles' => Permissions::roles(),
            'permissions' => Permissions::all(),
            'matrix' => Permissions::matrix(),
            'defaults' => config('crm.role_permissions'),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->boolean('reset')) {
            \App\Models\Setting::set('role_permissions', null);
            Permissions::flush();
            AuditLogger::log('permissions.reset');

            return back()->with('success', 'Permissions reset to defaults.');
        }

        $old = Permissions::matrix();
        $data = $request->validate(['perm' => 'nullable|array', 'perm.*' => 'array', 'perm.*.*' => 'string']);
        Permissions::save($data['perm'] ?? []);
        AuditLogger::log('permissions.updated', null, $old, Permissions::matrix());

        return back()->with('success', 'Permissions saved.');
    }
}
