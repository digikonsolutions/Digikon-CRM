<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(30),
            'types' => User::NOTIFICATION_TYPES,
            'prefs' => $request->user()->notification_preferences ?? [],
        ]);
    }

    /** Mark one notification read and open its link. */
    public function open(Request $request, string $id)
    {
        $n = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();
        $url = $n->data['url'] ?? null;

        // Only follow links inside this CRM.
        return $url && str_starts_with($url, url('/')) ? redirect($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }

    public function preferences(Request $request)
    {
        $prefs = [];
        foreach (array_keys(User::NOTIFICATION_TYPES) as $type) {
            $prefs[$type] = $request->boolean('notify.'.$type);
        }
        $prefs['email'] = $request->boolean('email');
        $request->user()->update(['notification_preferences' => $prefs]);

        return back()->with('success', 'Notification preferences saved.');
    }
}
