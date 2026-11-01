<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('logActivity', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(config('crm.loggable_activity_types'))],
            'outcome' => ['nullable', 'required_if:type,call', Rule::in(array_keys(config('crm.call_outcomes')))],
            'notes' => ['nullable', 'required_if:type,note', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'duration_seconds_part' => ['nullable', 'integer', 'min:0', 'max:59'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'complete_due_tasks' => ['nullable', 'boolean'],
            // user_id is intentionally NOT accepted — the logged-in user is always the actor.
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required_if' => 'Please choose the call outcome.',
            'notes.required_if' => 'Please write the note.',
            'next_follow_up_at.after' => 'The next follow-up must be in the future.',
            'occurred_at.before_or_equal' => 'The activity time cannot be in the future.',
        ];
    }

    public function activityData(): array
    {
        $data = $this->safe()->only(['type', 'outcome', 'notes', 'occurred_at', 'next_follow_up_at']);
        $data['complete_due_tasks'] = $this->boolean('complete_due_tasks');
        if ($this->filled('duration_minutes') || $this->filled('duration_seconds_part')) {
            $data['duration_seconds'] = (int) $this->input('duration_minutes', 0) * 60 + (int) $this->input('duration_seconds_part', 0);
        }

        return $data;
    }
}
