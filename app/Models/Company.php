<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Company extends TenantRecord
{
    protected $hidden = ['logo_path'];

    protected $appends = ['logo_url'];

    public function logoAsset()
    {
        return $this->hasOne(CompanyLogoAsset::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        $asset = $this->logoAsset()->first(['id', 'hash']);
        if ($asset) {
            return '/company/logo?v='.substr($asset->hash, 0, 12);
        }

        return $this->logo_path && Storage::disk('local')->exists($this->logo_path)
            ? '/company/logo?v='.substr(hash('sha256', $this->logo_path), 0, 12) : null;
    }

    public function getDocumentLogoAttribute(): ?string
    {
        $asset = $this->logoAsset()->first();
        if ($asset) {
            return 'data:'.$asset->mime.';base64,'.$asset->contents;
        }

        $disk = Storage::disk('local');
        if (! $this->logo_path || ! $disk->exists($this->logo_path)) {
            return null;
        }
        $mime = $disk->mimeType($this->logo_path);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($this->logo_path));
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'accepted_currencies' => 'array'];
    }
}
