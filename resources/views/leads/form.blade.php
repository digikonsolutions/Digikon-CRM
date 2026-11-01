@extends('layouts.app')
@section('title', $lead->exists ? 'Edit Lead' : 'New Lead')

@section('content')
<div class="page-head">
    <h1>{{ $lead->exists ? 'Edit Lead' : 'New Lead' }}</h1>
    <a href="{{ $lead->exists ? route('leads.show', $lead) : route('leads.index') }}" class="btn btn-outline">← Back</a>
</div>

<form method="POST" action="{{ $lead->exists ? route('leads.update', $lead) : route('leads.store') }}" class="card">
    @csrf
    @if($lead->exists) @method('PUT') @endif

    <div class="form-row">
        <div class="form-group">
            <label>Name *</label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $lead->name) }}" required>
        </div>
        <div class="form-group">
            <label>Phone / WhatsApp</label>
            <input type="text" name="phone" class="form-control" value="{{ old('phone', $lead->phone) }}" placeholder="03001234567 or +923001234567">
            <div class="help">Local numbers like 0300... automatically get country code {{ \App\Models\Setting::get('default_country_code', '92') }}.</div>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="{{ old('email', $lead->email) }}">
        </div>
        <div class="form-group">
            <label>Company</label>
            <input type="text" name="company" class="form-control" value="{{ old('company', $lead->company) }}">
        </div>
        <div class="form-group">
            <label>City</label>
            <input type="text" name="city" class="form-control" value="{{ old('city', $lead->city) }}">
        </div>
        <div class="form-group">
            <label>Service required</label>
            <input type="text" name="service" class="form-control" value="{{ old('service', $lead->service) }}" placeholder="Website, SEO, App ...">
        </div>
        <div class="form-group">
            <label>Budget</label>
            <input type="text" name="budget" class="form-control" value="{{ old('budget', $lead->budget) }}">
        </div>
        <div class="form-group">
            <label>Industry</label>
            <input type="text" name="industry" class="form-control" value="{{ old('industry', $lead->industry) }}" placeholder="Real estate, Retail ...">
        </div>
        <div class="form-group">
            <label>Timeline</label>
            <input type="text" name="timeline" class="form-control" value="{{ old('timeline', $lead->timeline) }}" placeholder="This month, Q1 ...">
        </div>
        <div class="form-group">
            <label>Priority</label>
            <select name="priority" class="form-control">
                @foreach(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $k => $v)
                    <option value="{{ $k }}" @selected(old('priority', $lead->priority ?? 'normal') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label>Source *</label>
            <select name="source" class="form-control">
                @foreach(\App\Models\Lead::SOURCES as $k => $v)
                    <option value="{{ $k }}" @selected(old('source', $lead->source) === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label>Campaign</label>
            <input type="text" name="campaign_name" class="form-control" value="{{ old('campaign_name', $lead->campaign_name) }}">
        </div>
        @if(! $lead->exists && auth()->user()->hasPermission('leads.assign'))
        <div class="form-group">
            <label>Assign to</label>
            <select name="assigned_to" class="form-control">
                <option value="">Auto (by distribution rule)</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected(old('assigned_to') == $a->id)>{{ $a->name }} ({{ $a->role_label }})</option>
                @endforeach
            </select>
        </div>
        @endif
    </div>
    <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control">{{ old('notes', $lead->notes) }}</textarea>
    </div>
    <button class="btn">Save Lead</button>
</form>
@endsection
