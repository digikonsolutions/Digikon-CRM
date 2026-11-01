@extends('layouts.app')
@section('title', 'Tags')

@section('content')
<div class="page-head"><h1>Tags</h1><span class="muted">Used to segment leads. The AI may also suggest tags (marked "AI" on the lead).</span></div>

<div class="grid grid-main">
    <div class="card">
        <table class="table">
            <thead><tr><th>Tag</th><th class="text-right">Leads</th><th>Edit</th><th></th></tr></thead>
            <tbody>
            @forelse($tags as $tag)
                <tr>
                    <td><span class="tag-chip"><span class="dot" style="background: {{ $tag->color }}"></span>{{ $tag->name }}</span></td>
                    <td class="text-right"><a href="{{ route('leads.index', ['tag' => $tag->id]) }}">{{ $tag->leads_count }}</a></td>
                    <td>
                        <form method="POST" action="{{ route('tags.update', $tag) }}" class="flex">
                            @csrf @method('PUT')
                            <input type="text" name="name" class="form-control" value="{{ $tag->name }}" maxlength="50" style="max-width:200px" aria-label="Tag name">
                            <input type="color" name="color" value="{{ $tag->color }}" class="form-control" style="width:48px;height:38px" aria-label="Tag colour">
                            <button class="btn btn-outline btn-sm">Save</button>
                        </form>
                    </td>
                    <td class="text-right">
                        <form method="POST" action="{{ route('tags.destroy', $tag) }}" class="inline-form" data-confirm="Delete tag &quot;{{ $tag->name }}&quot;? It will be removed from {{ $tag->leads_count }} lead(s).">
                            @csrf @method('DELETE') <button class="btn btn-red btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No tags yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>New tag</h2>
        <form method="POST" action="{{ route('tags.store') }}">
            @csrf
            <div class="form-group"><label for="t-name">Name</label><input id="t-name" type="text" name="name" class="form-control" maxlength="50" required></div>
            <div class="form-group"><label for="t-color">Colour</label><input id="t-color" type="color" name="color" class="form-control" value="#1F6E85"></div>
            <button class="btn">Add tag</button>
        </form>
    </div>
</div>
@endsection
