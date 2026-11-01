<?php

namespace App\Http\Controllers;

use App\Models\ServicePackage;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Price list used for automatic proposals. */
class ServicePackageController extends Controller
{
    public function index()
    {
        return view('packages.index', ['packages' => ServicePackage::orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('packages.form', ['package' => new ServicePackage(['items' => [['description' => '', 'qty' => 1, 'price' => 0]], 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $p = ServicePackage::create($this->validated($request));
        AuditLogger::log('package.created', $p, [], $p->only('name'));

        return redirect()->route('packages.index')->with('success', 'Package saved.');
    }

    public function edit(ServicePackage $package)
    {
        return view('packages.form', compact('package'));
    }

    public function update(Request $request, ServicePackage $package)
    {
        $package->update($this->validated($request, $package));
        AuditLogger::log('package.updated', $package, [], $package->only('name'));

        return redirect()->route('packages.index')->with('success', 'Package updated.');
    }

    public function destroy(ServicePackage $package)
    {
        AuditLogger::log('package.deleted', $package, $package->only('name'));
        $package->delete();

        return back()->with('success', 'Package deleted.');
    }

    protected function validated(Request $request, ?ServicePackage $package = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'keywords' => 'nullable|string|max:500',
            'title' => 'nullable|string|max:255',
            'introduction' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.qty' => 'nullable|numeric|min:0',
            'items.*.price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer',
        ]);
        $data['items'] = array_values(array_map(fn ($i) => ['description' => trim($i['description']), 'qty' => (float) ($i['qty'] ?? 1), 'price' => (float) ($i['price'] ?? 0)],
            array_filter($data['items'], fn ($i) => trim($i['description'] ?? '') !== '')));
        if (! $data['items']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'Add at least one item.']);
        }
        $data['discount'] = $data['discount'] ?? 0;
        $data['tax_percent'] = $data['tax_percent'] ?? 0;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');
        $data['is_default'] = $request->boolean('is_default');
        if ($data['is_default']) {
            ServicePackage::where('id', '!=', $package?->id ?? 0)->update(['is_default' => false]);
        }

        return $data;
    }
}
