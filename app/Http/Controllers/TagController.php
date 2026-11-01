<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function index()
    {
        return view('tags.index', ['tags' => Tag::withCount('leads')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $slug = Str::slug($data['name']);
        $request->merge(['slug' => $slug])->validate(['slug' => 'required|unique:tags,slug'], ['slug.unique' => 'This tag already exists.', 'slug.required' => 'Invalid tag name.']);

        $tag = Tag::create(['name' => $data['name'], 'slug' => $slug, 'color' => $data['color'] ?? '#1F6E85']);
        AuditLogger::log('tag.created', $tag, [], $tag->only('name'));

        return back()->with('success', 'Tag created.');
    }

    public function update(Request $request, Tag $tag)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $slug = Str::slug($data['name']);
        $request->merge(['slug' => $slug])->validate(['slug' => ['required', Rule::unique('tags', 'slug')->ignore($tag->id)]], ['slug.unique' => 'A tag with this name already exists.']);

        $old = $tag->only('name', 'color');
        $tag->update(['name' => $data['name'], 'slug' => $slug, 'color' => $data['color'] ?? $tag->color]);
        AuditLogger::log('tag.updated', $tag, $old, $tag->only('name', 'color'));

        return back()->with('success', 'Tag updated.');
    }

    public function destroy(Tag $tag)
    {
        AuditLogger::log('tag.deleted', $tag, $tag->only('name'));
        $tag->delete();

        return back()->with('success', 'Tag deleted (removed from all leads).');
    }
}
