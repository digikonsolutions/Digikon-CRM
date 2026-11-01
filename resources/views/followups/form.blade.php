@extends('layouts.app')
@section('title', 'Follow-up Step')

@php
    $m = $step->delay_minutes ?? 0;
    [$val, $unit] = $m && $m % 1440 === 0 ? [$m / 1440, 'days'] : ($m && $m % 60 === 0 ? [$m / 60, 'hours'] : [$m, 'minutes']);
@endphp

@section('content')
<div class="page-head">
    <h1>{{ $step->exists ? 'Edit' : 'New' }} Follow-up Step</h1>
    <a href="{{ route('followups.index') }}" class="btn btn-outline">← Back</a>
</div>

<form method="POST" action="{{ $step->exists ? route('followups.update', $step) : route('followups.store') }}" class="card">
    @csrf
    @if($step->exists) @method('PUT') @endif

    <div class="form-row">
        <div class="form-group">
            <label>Step name *</label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $step->name) }}" placeholder="Day 1 reminder" required>
        </div>
        <div class="form-group">
            <label>Order</label>
            <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $step->sort_order ?? 0) }}">
        </div>
        <div class="form-group">
            <label>Send how long after the lead arrives? (0 = immediately)</label>
            <div class="flex">
                <input type="number" name="delay_value" min="0" class="form-control" value="{{ old('delay_value', $val) }}">
                <select name="delay_unit" class="form-control">
                    @foreach(['minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days'] as $k => $v)
                        <option value="{{ $k }}" @selected(old('delay_unit', $unit) === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Channel</label>
            <select name="channel" class="form-control" id="channel">
                @foreach(\App\Models\FollowupStep::CHANNELS as $k => $v)
                    <option value="{{ $k }}" @selected(old('channel', $step->channel ?? 'whatsapp') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" id="mtype-group">
            <label>Message type</label>
            <select name="message_type" class="form-control" id="mtype">
                <option value="template" @selected(old('message_type', $step->message_type) === 'template')>Approved WhatsApp template (recommended)</option>
                <option value="text" @selected(old('message_type', $step->message_type) === 'text')>Free text (only within the 24h window)</option>
            </select>
        </div>
    </div>

    <div id="tpl-fields" class="form-row">
        <div class="form-group">
            <label>Template name (as approved in WhatsApp Manager)</label>
            <input type="text" name="template_name" class="form-control" value="{{ old('template_name', $step->template_name) }}" placeholder="lead_welcome">
        </div>
        <div class="form-group">
            <label>Template language code</label>
            <input type="text" name="template_language" class="form-control" value="{{ old('template_language', $step->template_language) }}" placeholder="en / en_US / ur">
        </div>
    </div>

    <div class="form-group" id="subject-group">
        <label>Email subject</label>
        <input type="text" name="email_subject" class="form-control" value="{{ old('email_subject', $step->email_subject) }}" placeholder="Thank you for contacting {company}">
    </div>

    <div class="form-group">
        <label id="msg-label">Template variables</label>
        <textarea name="message" class="form-control">{{ old('message', $step->message) }}</textarea>
        <div class="help" id="msg-help"></div>
    </div>

    <div class="form-group">
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $step->is_active))> Active</label>
    </div>
    <button class="btn">Save Step</button>
</form>
@endsection

@push('scripts')
<script>
    const sel = document.getElementById('mtype');
    const channel = document.getElementById('channel');
    function sync() {
        const email = channel.value === 'email';
        document.getElementById('mtype-group').style.display = email ? 'none' : '';
        document.getElementById('subject-group').style.display = email ? '' : 'none';
        const tpl = !email && sel.value === 'template';
        document.getElementById('tpl-fields').style.display = tpl ? '' : 'none';
        document.getElementById('msg-label').textContent = tpl ? 'Template variables ( @{{1}}, @{{2}} ... in order, separated by | )' : (email ? 'Email text' : 'Message text');
        document.getElementById('msg-help').innerHTML = tpl
            ? 'Example: <code>{name}|{company}</code> → template @{{1}} = lead name, @{{2}} = company name. Placeholders: {name} {agent} {agent_phone} {company} {service}'
            : 'Placeholders: {name} {agent} {agent_phone} {company} {service}';
    }
    sel.addEventListener('change', sync); channel.addEventListener('change', sync); sync();
</script>
@endpush
