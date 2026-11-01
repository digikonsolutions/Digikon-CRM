@extends('layouts.app')
@section('title', $proposal->exists ? 'Edit Proposal' : 'New Proposal')

@php $items = old('items', $proposal->items ?: [['description' => '', 'qty' => 1, 'price' => 0]]); @endphp

@section('content')
<div class="page-head">
    <h1>{{ $proposal->exists ? 'Edit Proposal '.$proposal->number : 'New Proposal' }}</h1>
    <a href="{{ $proposal->exists ? route('proposals.show', $proposal) : route('proposals.index') }}" class="btn btn-outline">← Back</a>
</div>

<form method="POST" action="{{ $proposal->exists ? route('proposals.update', $proposal) : route('proposals.store') }}">
    @csrf
    @if($proposal->exists) @method('PUT') @endif
    <input type="hidden" name="lead_id" value="{{ old('lead_id', $proposal->lead_id) }}">

    <div class="card">
        <h2>1. Choose a template</h2>
        <div class="template-picker">
            @foreach(\App\Models\Proposal::TEMPLATES as $key => $label)
                <label class="{{ old('template', $proposal->template) === $key ? 'selected' : '' }}">
                    <input type="radio" name="template" value="{{ $key }}" @checked(old('template', $proposal->template) === $key)>
                    <strong>{{ ucfirst($key) }}</strong><br><small>{{ $label }}</small>
                </label>
            @endforeach
        </div>
        <p class="help mt">Logo, colors and signature come from <a href="{{ route('settings.index', ['tab' => 'branding']) }}">Settings → Proposal Branding</a>.</p>
    </div>

    <div class="card">
        <h2>2. Client details</h2>
        <div class="form-row">
            <div class="form-group"><label>Proposal title *</label><input type="text" name="title" class="form-control" value="{{ old('title', $proposal->title) }}" required></div>
            <div class="form-group"><label>Client name *</label><input type="text" name="client_name" class="form-control" value="{{ old('client_name', $proposal->client_name) }}" required></div>
            <div class="form-group"><label>Client email</label><input type="email" name="client_email" class="form-control" value="{{ old('client_email', $proposal->client_email) }}"></div>
            <div class="form-group"><label>Client company</label><input type="text" name="client_company" class="form-control" value="{{ old('client_company', $proposal->client_company) }}"></div>
        </div>
        <div class="form-group">
            <label>Introduction</label>
            <textarea name="introduction" class="form-control" rows="4">{{ old('introduction', $proposal->introduction) }}</textarea>
        </div>
    </div>

    <div class="card">
        <h2>3. Services / Items</h2>
        <table class="table items-table">
            <thead><tr><th style="width:55%">Description</th><th style="width:12%">Qty</th><th style="width:18%">Price</th><th class="text-right">Amount</th><th></th></tr></thead>
            <tbody id="items">
            @foreach($items as $i => $item)
                <tr>
                    <td><textarea name="items[{{ $i }}][description]" class="form-control" rows="2" placeholder="e.g. 5-page business website with SEO setup">{{ $item['description'] ?? '' }}</textarea></td>
                    <td><input type="number" step="any" min="0" name="items[{{ $i }}][qty]" class="form-control qty" value="{{ $item['qty'] ?? 1 }}"></td>
                    <td><input type="number" step="any" min="0" name="items[{{ $i }}][price]" class="form-control price" value="{{ $item['price'] ?? 0 }}"></td>
                    <td class="text-right amount">0</td>
                    <td><button type="button" class="btn btn-red btn-sm remove">×</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <button type="button" class="btn btn-outline btn-sm mt" id="add-item">+ Add item</button>

        <div class="form-row-3 mt">
            <div class="form-group"><label>Currency</label><input type="text" name="currency" class="form-control" value="{{ old('currency', $proposal->currency) }}"></div>
            <div class="form-group"><label>Discount (amount)</label><input type="number" step="any" min="0" name="discount" id="discount" class="form-control" value="{{ old('discount', $proposal->discount ?? 0) }}"></div>
            <div class="form-group"><label>Tax %</label><input type="number" step="any" min="0" max="100" name="tax_percent" id="tax" class="form-control" value="{{ old('tax_percent', $proposal->tax_percent ?? 0) }}"></div>
        </div>
        <div class="totals">
            <div><span>Subtotal</span><span id="subtotal">0</span></div>
            <div><span>Discount</span><span id="discount-view">0</span></div>
            <div><span>Tax</span><span id="tax-view">0</span></div>
            <div class="grand"><span>Total</span><span id="total">0</span></div>
        </div>
    </div>

    <div class="card">
        <h2>4. Terms</h2>
        <div class="form-row">
            <div class="form-group"><label>Valid until</label><input type="date" name="valid_until" class="form-control" value="{{ old('valid_until', $proposal->valid_until?->format('Y-m-d')) }}"></div>
        </div>
        <div class="form-group">
            <label>Terms & conditions</label>
            <textarea name="terms" class="form-control" rows="5">{{ old('terms', $proposal->terms) }}</textarea>
        </div>
    </div>

    <button class="btn btn-green">Save & Preview</button>
</form>
@endsection

@push('scripts')
<script>
    const tbody = document.getElementById('items');
    let index = tbody.children.length;
    const fmt = n => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function calc() {
        let subtotal = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            const amount = (parseFloat(tr.querySelector('.qty').value) || 0) * (parseFloat(tr.querySelector('.price').value) || 0);
            tr.querySelector('.amount').textContent = fmt(amount);
            subtotal += amount;
        });
        const discount = parseFloat(document.getElementById('discount').value) || 0;
        const taxable = Math.max(subtotal - discount, 0);
        const tax = taxable * (parseFloat(document.getElementById('tax').value) || 0) / 100;
        document.getElementById('subtotal').textContent = fmt(subtotal);
        document.getElementById('discount-view').textContent = '- ' + fmt(discount);
        document.getElementById('tax-view').textContent = fmt(tax);
        document.getElementById('total').textContent = fmt(taxable + tax);
    }

    document.getElementById('add-item').addEventListener('click', () => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><textarea name="items[${index}][description]" class="form-control" rows="2"></textarea></td>
            <td><input type="number" step="any" min="0" name="items[${index}][qty]" class="form-control qty" value="1"></td>
            <td><input type="number" step="any" min="0" name="items[${index}][price]" class="form-control price" value="0"></td>
            <td class="text-right amount">0</td>
            <td><button type="button" class="btn btn-red btn-sm remove">×</button></td>`;
        tbody.appendChild(tr);
        index++;
        calc();
    });

    tbody.addEventListener('click', e => {
        if (e.target.classList.contains('remove') && tbody.children.length > 1) {
            e.target.closest('tr').remove();
            calc();
        }
    });
    document.addEventListener('input', calc);

    document.querySelectorAll('.template-picker input').forEach(r => r.addEventListener('change', () => {
        document.querySelectorAll('.template-picker label').forEach(l => l.classList.remove('selected'));
        r.closest('label').classList.add('selected');
    }));
    calc();
</script>
@endpush
