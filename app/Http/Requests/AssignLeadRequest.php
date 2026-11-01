<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('lead'));
    }

    public function rules(): array
    {
        $lead = $this->route('lead');

        return [
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            // A reason is required when the lead already has an owner (reassign / unassign).
            'reason' => [$lead && $lead->assigned_to ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Please give a reason for reassigning / unassigning this lead.'];
    }
}
