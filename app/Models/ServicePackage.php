<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Price list used to build automatic proposals (Settings → Service Packages). */
class ServicePackage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean', 'discount' => 'float', 'tax_percent' => 'float'];
    }

    public function keywordList(): array
    {
        return array_values(array_filter(array_map(fn ($k) => Str::lower(trim($k)), explode(',', (string) $this->keywords))));
    }

    public function subtotal(): float
    {
        return collect($this->items ?? [])->sum(fn ($i) => (float) ($i['qty'] ?? 1) * (float) ($i['price'] ?? 0));
    }

    /**
     * Best package for a service text ("E-commerce store", "website banwani hai").
     * Longest matching keyword wins; falls back to the default package.
     */
    public static function match(?string ...$texts): ?self
    {
        $haystack = Str::lower(implode(' ', array_filter($texts)));
        $best = null;
        $bestLen = 0;
        if ($haystack !== '') {
            foreach (static::where('is_active', true)->orderBy('sort_order')->get() as $pkg) {
                foreach (array_merge($pkg->keywordList(), [Str::lower($pkg->name)]) as $kw) {
                    if ($kw !== '' && mb_strlen($kw) > $bestLen && preg_match('/(?<![\pL\pN])'.preg_quote($kw, '/').'(?![\pL\pN])/u', $haystack)) {
                        $best = $pkg;
                        $bestLen = mb_strlen($kw);
                    }
                }
            }
        }

        return $best ?? static::where('is_active', true)->where('is_default', true)->first();
    }
}
