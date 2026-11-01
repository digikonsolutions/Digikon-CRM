<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Public landing-page submission. Safe JSON errors, no internal details. */
class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Field names accepted from different forms → CRM field.
     * e.g. the DIGIKON "Talk to us" form sends work_email / business / automate.
     */
    public const ALIASES = [
        'name' => ['full_name', 'fullname', 'your_name'],
        'email' => ['work_email', 'workemail', 'email_address'],
        'phone' => ['whatsapp', 'whatsapp_number', 'mobile', 'phone_number', 'contact_number'],
        'company' => ['business', 'business_name', 'business_website', 'company_or_website', 'website', 'website_url'],
        'interested_service' => ['automate', 'automation', 'what_to_automate', 'service', 'priority', 'need'],
    ];

    protected function prepareForValidation(): void
    {
        $aliased = [];
        foreach (self::ALIASES as $field => $names) {
            if (blank($this->input($field))) {
                foreach ($names as $alias) {
                    if (is_string($this->input($alias)) && trim($this->input($alias)) !== '') {
                        $aliased[$field] = $this->input($alias);
                        break;
                    }
                }
            }
        }
        $this->merge($aliased);

        $clean = [];
        foreach (['name', 'email', 'phone', 'company', 'interested_service', 'budget', 'message', 'city',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referrer', 'landing_page_url', 'submission_id'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim(strip_tags($value));
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        if (! empty($clean['email'])) {
            $clean['email'] = mb_strtolower($clean['email']);
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'company' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'interested_service' => ['nullable', 'string', 'max:190'],
            'budget' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:3000'],
            'landing_page_url' => ['nullable', 'url', 'max:1000'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:190'],
            'utm_medium' => ['nullable', 'string', 'max:190'],
            'utm_campaign' => ['nullable', 'string', 'max:190'],
            'utm_content' => ['nullable', 'string', 'max:190'],
            'submission_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]{8,100}$/'],
            'consent' => ['nullable', 'boolean'],
            'redirect_to' => ['nullable', 'url', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'Please enter your phone number or email.',
            'phone.required_without' => 'Please enter your phone number or email.',
            'phone.regex' => 'Please enter a valid phone number.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'Please check the form and try again.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
