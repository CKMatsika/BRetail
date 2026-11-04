<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyLogoController
{
    public function store(Request $request)
    {
        abort_unless($request->user()->canDo('company.configure'), 403);
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp,gif,bmp|max:2048|dimensions:max_width=4096,max_height=4096']);
        $file = $request->file('logo');
        $image = @imagecreatefromstring($file->get());
        if ($image === false) {
            throw ValidationException::withMessages(['logo' => 'This image could not be read. Please upload a PNG, JPEG, WebP, GIF or BMP logo.']);
        }
        // Preserve transparency and store browser-compatible pixels, regardless of the source format.
        imagesavealpha($image, true);
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagepng($image, $stream)) {
                throw new \RuntimeException('Could not encode company logo.');
            }
            rewind($stream);
            $path = 'company-logos/'.$request->user()->tenant_id.'/'.Str::uuid().'.png';
            if (! Storage::disk('local')->put($path, $stream)) {
                throw new \RuntimeException('Could not save company logo.');
            }
        } finally {
            fclose($stream);
            imagedestroy($image);
        }
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
        abort_unless($company->logo_path && Storage::disk('local')->exists($company->logo_path), 404);

        return response()->file(Storage::disk('local')->path($company->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
