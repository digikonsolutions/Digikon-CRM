<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Reschedule / cancel / outcome. */
class UpdateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:reschedule,cancel,outcome'],
            'starts_at' => ['required_if:action,reschedule', 'nullable', 'date', 'after:-5 minutes'],
            'ends_at' => ['required_if:action,reschedule', 'nullable', 'date', 'after:starts_at'],
            'meeting_type' => ['nullable', Rule::in(array_keys(config('crm.meeting_types')))],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url', 'max:1000'],
            'reason' => ['nullable', 'required_if:action,cancel', 'string', 'max:500'],
            'status' => ['required_if:action,outcome', 'nullable', 'in:completed,no_show'],
            'outcome' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'allow_conflict' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'The meeting must end after it starts.',
            'reason.required_if' => 'Please give a cancellation reason.',
        ];
    }
}
