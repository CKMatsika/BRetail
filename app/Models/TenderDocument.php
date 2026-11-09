<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderDocument extends TenantRecord
{
    protected $table = 'tender_documents';

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }
}
