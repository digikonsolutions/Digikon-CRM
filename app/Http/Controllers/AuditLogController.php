<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user:id,name')
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->action, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($request->type, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($request->id, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($request->date_from, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->date_to, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->latest('created_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'types' => AuditLog::whereNotNull('auditable_type')->distinct()->pluck('auditable_type'),
        ]);
    }
}
