<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

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
            $bytes = stream_get_contents($stream);
            $path = 'company-logos/'.$request->user()->tenant_id.'/'.Str::uuid().'.png';
        } finally {
            fclose($stream);
            imagedestroy($image);
        }
        DB::transaction(function () use ($path, $bytes) {
            $company = Company::lockForUpdate()->firstOrFail();
            $company->logoAsset()->updateOrCreate([], ['mime' => 'image/png', 'hash' => hash('sha256', $bytes), 'contents' => base64_encode($bytes)]);
            $company->update(['logo_path' => $path]);
            Audit::record('company.logo_updated', (string) $company->id);
        });
        // The database is authoritative; local files are only a compatibility cache.
        try {
            Storage::disk('local')->put($path, $bytes);
        } catch (FilesystemException $e) {
            report($e);
        }

        return back()->with('success', 'Company logo updated.');
    }

    public function show(Request $request)
    {
        $company = Company::firstOrFail();
        $asset = $company->logoAsset()->first();
        if ($asset) {
            return response(base64_decode($asset->contents, true), 200, ['Content-Type' => $asset->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        abort_unless($company->logo_path && Storage::disk('local')->exists($company->logo_path), 404);

        return response()->file(Storage::disk('local')->path($company->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
