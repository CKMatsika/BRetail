<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyLogoController
{
    public function store(Request $request)
    {
        abort_unless($request->user()->canDo('company.configure'), 403);
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:max_width=4096,max_height=4096']);
        $file = $request->file('logo');
        $path = $file->store('company-logos/'.$request->user()->tenant_id, 'local');
        try {
            DB::transaction(function () use ($path) {
                $company = Company::firstOrFail();
                $company->update(['logo_path' => $path]);
                Audit::record('company.logo_updated', (string) $company->id);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Company logo updated.');
    }

    public function show(Request $request)
    {
        $company = Company::firstOrFail();
        abort_unless($company->logo_path, 404);

        return response()->file(Storage::disk('local')->path($company->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
