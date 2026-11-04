<?php

namespace App\Models;

class TradeDocument extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['payload_hash'];

    public function scopeCurrent($query)
    {
        return $query->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('trade_documents as versions')
                ->whereColumn('versions.tenant_id', 'trade_documents.tenant_id')
                ->whereColumn('versions.parent_id', 'trade_documents.id')
                ->whereColumn('versions.kind', 'trade_documents.kind')
                ->whereIn('versions.kind', ['quote', 'invoice']);
        });
    }

    public function versions(): array
    {
        $versions = [$this];
        $document = $this;
        while ($document->parent_id && in_array($document->kind, ['quote', 'invoice'], true)) {
            $parent = self::find($document->parent_id);
            if (! $parent || $parent->kind !== $document->kind) {
                break;
            }
            $versions[] = $parent;
            $document = $parent;
        }

        return $versions;
    }

    public function getDisplayNumberAttribute(): string
    {
        $versions = $this->versions();

        return end($versions)->number;
    }

    protected function casts(): array
    {
        return ['currency_snapshot' => 'array', 'items' => 'array', 'net' => 'decimal:4', 'tax' => 'decimal:4', 'total' => 'decimal:4'];
    }
}
