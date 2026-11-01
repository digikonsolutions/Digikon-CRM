<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Lightweight role → permission resolver (replaces Spatie Permission).
 * Defaults live in config/crm.php; the admin-edited matrix is stored in the
 * "role_permissions" setting (JSON) and overrides the defaults per role.
 */
class Permissions
{
    protected static ?array $matrix = null;

    public static function all(): array
    {
        return config('crm.permissions', []);
    }

    public static function roles(): array
    {
        return config('crm.roles', []);
    }

    public static function matrix(): array
    {
        if (static::$matrix !== null) {
            return static::$matrix;
        }

        $defaults = config('crm.role_permissions', []);
        $saved = [];
        try {
            $saved = json_decode((string) Setting::get('role_permissions', ''), true) ?: [];
        } catch (\Throwable) {
            // settings table may not exist yet (fresh install / tests before migrate)
        }

        $valid = array_keys(static::all());
        // Permissions added in a later CRM version (not in the saved list) use their default.
        $known = isset($saved['_known']) && is_array($saved['_known']) ? $saved['_known'] : null;
        if ($known === null && $saved) {
            $known = array_diff($valid, ['invoices.manage']); // matrix saved by the first upgrade version
        }
        $matrix = [];
        foreach (array_keys(static::roles()) as $role) {
            $perms = array_key_exists($role, $saved) && is_array($saved[$role]) ? $saved[$role] : ($defaults[$role] ?? []);
            if (array_key_exists($role, $saved) && $known !== null) {
                $perms = array_merge($perms, array_diff($defaults[$role] ?? [], $known));
            }
            $matrix[$role] = array_values(array_unique(array_intersect($perms, $valid)));
        }

        // Lock-out protection: admin always keeps settings + user management.
        $matrix['admin'] = array_values(array_unique(array_merge($matrix['admin'] ?? [], ['settings.manage', 'users.manage'])));

        return static::$matrix = $matrix;
    }

    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, static::matrix()[$role] ?? [], true);
    }

    public static function save(array $matrix): void
    {
        $valid = array_keys(static::all());
        $clean = [];
        foreach (array_keys(static::roles()) as $role) {
            $clean[$role] = array_values(array_intersect((array) ($matrix[$role] ?? []), $valid));
        }
        $clean['_known'] = $valid;
        Setting::set('role_permissions', json_encode($clean));
        static::flush();
    }

    public static function flush(): void
    {
        static::$matrix = null;
    }
}
