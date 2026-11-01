<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkAssignLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leads.assign');
    }

    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer', 'distinct'],
            'action' => ['required', 'in:assign,round_robin,least_leads,unassign'],
            'assigned_to' => ['nullable', 'required_if:action,assign', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lead_ids.required' => 'Select at least one lead.',
            'assigned_to.required_if' => 'Choose the agent to assign the selected leads to.',
        ];
    }
}
