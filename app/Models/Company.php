<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Company extends TenantRecord
{
    protected $hidden = ['logo_path'];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/company/logo?v='.substr(hash('sha256', $this->logo_path), 0, 12) : null;
    }

    public function getDocumentLogoAttribute(): ?string
    {
        $disk = Storage::disk('local');
        if (! $this->logo_path || ! $disk->exists($this->logo_path)) {
            return null;
        }
        $mime = $disk->mimeType($this->logo_path);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($this->logo_path));
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'accepted_currencies' => 'array'];
    }
}
