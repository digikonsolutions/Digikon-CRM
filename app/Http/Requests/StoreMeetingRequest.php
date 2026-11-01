<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('scheduleMeeting', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'meeting_type' => ['required', Rule::in(array_keys(config('crm.meeting_types')))],
            'starts_at' => ['required', 'date', 'after:-5 minutes'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'allow_conflict' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'The meeting must end after it starts.',
            'starts_at.after' => 'The meeting time is in the past.',
        ];
    }
}
