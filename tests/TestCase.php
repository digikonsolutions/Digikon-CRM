<?php

namespace Tests;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function user(string $role = 'agent', array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => ucfirst($role).' '.$n,
            'email' => $role.$n.'@test.local',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'receives_leads' => true,
        ], $attrs));
    }

    protected function lead(array $attrs = []): Lead
    {
        static $p = 0;
        $p++;

        return Lead::create(array_merge([
            'name' => 'Lead '.$p,
            'phone' => '9230000'.str_pad((string) $p, 5, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'status' => 'new',
            'priority' => 'normal',
        ], $attrs));
    }
}
