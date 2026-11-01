<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $guarded = ['id'];

    public function leads()
    {
        return $this->belongsToMany(Lead::class)->withPivot('source', 'added_by')->withTimestamps();
    }

    /** Find a tag by name (case-insensitive slug) or create it. */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $slug = Str::slug($name) ?: Str::random(8);

        return static::firstOrCreate(['slug' => $slug], ['name' => Str::limit($name, 50, '')]);
    }
}
