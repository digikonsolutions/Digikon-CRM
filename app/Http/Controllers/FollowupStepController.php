<?php

namespace App\Http\Controllers;

use App\Models\FollowupStep;
use App\Models\LeadFollowup;
use App\Models\Setting;
use Illuminate\Http\Request;

class FollowupStepController extends Controller
{
    public function index()
    {
        return view('followups.index', [
            'steps' => FollowupStep::orderBy('sort_order')->orderBy('delay_minutes')->get(),
            'autoFollowups' => Setting::get('auto_followups', '1') === '1',
            'queue' => LeadFollowup::with(['lead', 'step'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')->limit(50)->get(),
        ]);
    }

    public function create()
    {
        return view('followups.form', ['step' => new FollowupStep(['channel' => request('channel', 'whatsapp'), 'message_type' => request('channel') === 'email' ? 'text' : 'template', 'template_language' => 'en', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        FollowupStep::create($this->validated($request));

        return redirect()->route('followups.index')->with('success', 'Follow-up step added.');
    }

    public function edit(FollowupStep $followup)
    {
        return view('followups.form', ['step' => $followup]);
    }

    public function update(Request $request, FollowupStep $followup)
    {
        $followup->update($this->validated($request));

        return redirect()->route('followups.index')->with('success', 'Follow-up step updated.');
    }

    public function destroy(FollowupStep $followup)
    {
        $followup->delete();

        return back()->with('success', 'Step deleted.');
    }

    public function toggle()
    {
        $on = Setting::get('auto_followups', '1') === '1';
        Setting::set('auto_followups', $on ? '0' : '1');

        return back()->with('success', $on ? 'Auto follow-ups turned OFF.' : 'Auto follow-ups turned ON.');
    }

    protected function validated(Request $request): array
    {
        if ($request->input('channel') === 'email') {
            $request->merge(['message_type' => 'text']);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'channel' => 'required|in:whatsapp,email',
            'email_subject' => 'nullable|required_if:channel,email|string|max:255',
            'delay_value' => 'required|integer|min:0',
            'delay_unit' => 'required|in:minutes,hours,days',
            'message_type' => 'required|in:template,text',
            'template_name' => 'nullable|required_if:message_type,template|string|max:255',
            'template_language' => 'nullable|string|max:10',
            'message' => 'nullable|required_if:message_type,text|string|max:6000',
            'sort_order' => 'nullable|integer',
        ]);

        $multiplier = ['minutes' => 1, 'hours' => 60, 'days' => 1440][$data['delay_unit']];
        $data['delay_minutes'] = $data['delay_value'] * $multiplier;
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['template_language'] = ($data['template_language'] ?? '') ?: 'en';
        $data['template_name'] = $data['channel'] === 'email' ? null : ($data['template_name'] ?? null);
        $data['email_subject'] = $data['channel'] === 'email' ? $data['email_subject'] : null;
        unset($data['delay_value'], $data['delay_unit']);

        return $data;
    }
}
