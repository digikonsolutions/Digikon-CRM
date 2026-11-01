<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::withCount([
                'leads',
                'leads as open_leads_count' => fn ($q) => $q->whereNotIn('status', Lead::CLOSED_STATUSES),
            ])->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => 'agent', 'is_active' => true, 'receives_leads' => true])]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request));
        AuditLogger::log('user.created', $user, [], $user->only(['name', 'email', 'role', 'is_active']));

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->id === $request->user()->id) {
            // You cannot change your own role or lock yourself out.
            $data['role'] = $user->role;
            $data['is_active'] = true;
        }

        $old = $user->only(['name', 'email', 'role', 'is_active', 'receives_leads']);
        $user->update($data);
        AuditLogger::log('user.updated', $user, $old, $user->only(['name', 'email', 'role', 'is_active', 'receives_leads']));

        $msg = 'User updated.';
        $open = $user->leads()->open()->count();
        if ($open && (! $user->is_active || ! $user->canOwnLeads())) {
            $msg .= " {$user->name} still owns {$open} open lead(s) — reassign them from Leads → filter by this agent.";
        }

        return redirect()->route('users.index')->with('success', $msg);
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');

        DB::transaction(function () use ($user, $request) {
            // Close the ownership history properly before the user disappears.
            foreach (Lead::where('assigned_to', $user->id)->get() as $lead) {
                $lead->log('assigned', "Owner {$user->name} was deleted — lead is now unassigned", $request->user()->id);
            }
            LeadAssignment::where('user_id', $user->id)->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'unassigned_by' => $request->user()->id]);
            AuditLogger::log('user.deleted', $user, $user->only(['name', 'email', 'role']));
            $user->delete();
        });

        return back()->with('success', 'User deleted. Their leads are now unassigned (see Unassigned leads).');
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::in(array_keys(config('crm.roles')))],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['receives_leads'] = $request->boolean('receives_leads');

        return $data;
    }
}
