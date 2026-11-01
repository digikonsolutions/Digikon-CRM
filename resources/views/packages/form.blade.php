@extends('layouts.app')
@section('title', 'Service Package')

@section('content')
<div class="page-head">
    <h1>{{ $package->exists ? 'Edit' : 'New' }} Service Package</h1>
    <a href="{{ route('packages.index') }}" class="btn btn-outline">← Back</a>
</div>

<form method="POST" action="{{ $package->exists ? route('packages.update', $package) : route('packages.store') }}" class="card">
    @csrf @if($package->exists) @method('PUT') @endif
    <div class="form-row">
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $package->name) }}" required></div>
        <div class="form-group"><label>Proposal title</label><input type="text" name="title" class="form-control" value="{{ old('title', $package->title) }}" placeholder="Website Development Proposal"></div>
    </div>
    <div class="form-group">
        <label>Keywords (comma separated)</label>
        <input type="text" name="keywords" class="form-control" value="{{ old('keywords', $package->keywords) }}" placeholder="website, web site, landing page">
        <div class="help">Matched (as whole words) against the lead's service and the AI's "service interest".</div>
    </div>
    <div class="form-group">
        <label>Introduction (optional — uses the default proposal introduction when empty)</label>
        <textarea name="introduction" class="form-control">{{ old('introduction', $package->introduction) }}</textarea>
    </div>

    <h3>Items</h3>
    <table class="table items-table" id="items">
        <thead><tr><th>Description</th><th style="width:110px">Qty</th><th style="width:160px">Price</th><th style="width:40px"></th></tr></thead>
        <tbody>
        @foreach(old('items', $package->items ?? []) as $i => $item)
            <tr>
                <td><input type="text" name="items[{{ $i }}][description]" class="form-control" value="{{ $item['description'] ?? '' }}" aria-label="Description"></td>
                <td><input type="number" step="0.01" min="0" name="items[{{ $i }}][qty]" class="form-control" value="{{ $item['qty'] ?? 1 }}" aria-label="Qty"></td>
                <td><input type="number" step="0.01" min="0" name="items[{{ $i }}][price]" class="form-control" value="{{ $item['price'] ?? 0 }}" aria-label="Price"></td>
                <td><button type="button" class="btn btn-red btn-sm" onclick="this.closest('tr').remove()" aria-label="Remove">×</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <button type="button" class="btn btn-outline btn-sm" id="add-item">+ Add item</button>

    <div class="form-row-3 mt">
        <div class="form-group"><label>Discount</label><input type="number" step="0.01" min="0" name="discount" class="form-control" value="{{ old('discount', $package->discount ?? 0) }}"></div>
        <div class="form-group"><label>Tax %</label><input type="number" step="0.01" min="0" max="100" name="tax_percent" class="form-control" value="{{ old('tax_percent', $package->tax_percent ?? 0) }}"></div>
        <div class="form-group"><label>Order</label><input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $package->sort_order ?? 0) }}"></div>
    </div>
    <div class="form-group"><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active))> Active</label></div>
    <div class="form-group"><label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $package->is_default))> Default package (used when no keyword matches)</label></div>
    <button class="btn">Save package</button>
</form>
@endsection

@push('scripts')
<script>
    document.getElementById('add-item').addEventListener('click', () => {
        const tbody = document.querySelector('#items tbody');
        const i = Date.now();
        tbody.insertAdjacentHTML('beforeend', `<tr>
            <td><input type="text" name="items[${i}][description]" class="form-control"></td>
            <td><input type="number" step="0.01" min="0" name="items[${i}][qty]" class="form-control" value="1"></td>
            <td><input type="number" step="0.01" min="0" name="items[${i}][price]" class="form-control" value="0"></td>
            <td><button type="button" class="btn btn-red btn-sm" onclick="this.closest('tr').remove()">×</button></td></tr>`);
    });
</script>
@endpush
