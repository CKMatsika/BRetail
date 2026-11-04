<?php

namespace App\Services;

use App\Models\Classification;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogCsv
{
    public const HEADERS = ['product_name', 'variant_name', 'sku', 'barcode', 'department', 'category', 'subcategory', 'brand', 'unit', 'cost_price', 'selling_price', 'wholesale_price', 'reorder_level', 'attributes_json'];

    public function import(User $user, UploadedFile $file): int
    {
        InventoryAccess::permit($user, 'inventory.import');
        InventoryAccess::permit($user, 'inventory.configure');
        $stream = fopen($file->getRealPath(), 'r');
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }if ($header !== self::HEADERS && $header !== [...self::HEADERS, 'item_type']) {
                throw ValidationException::withMessages(['file' => 'CSV headers must match the download template exactly.']);
            }
            $rows = [];
            $number = 1;
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $number++;
                if ($row === [null]) {
                    continue;
                }if (count($row) !== count($header)) {
                    throw ValidationException::withMessages(['file' => "Row $number has the wrong number of columns."]);
                }$rows[] = ['row' => $number, 'data' => array_combine($header, $row)];
                if (count($rows) > 1000) {
                    throw ValidationException::withMessages(['file' => 'Import at most 1000 variants per file.']);
                }
            }
        } finally {
            fclose($stream);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file has no product rows.']);
        }

        return DB::transaction(function () use ($user, $rows) {
            $catalog = app(Catalog::class);
            $catalog->lock();
            foreach ($rows as $entry) {
                $r = $entry['data'];
                if (isset($r['item_type']) && ! in_array($r['item_type'], ['stock', 'service'], true)) {
                    throw ValidationException::withMessages(['file' => 'Row '.$entry['row'].': item_type must be stock or service.']);
                }
                try {
                    $attributes = json_decode($r['attributes_json'] ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($attributes)) {
                        throw new \JsonException('Attributes must be a JSON object.');
                    }
                    $parent = null;
                    $classification = [];
                    foreach (['department', 'category', 'subcategory'] as $level) {
                        if ($r[$level] === '') {
                            $classification[$level.'_id'] = null;
                            $parent = null;

                            continue;
                        }$c = Classification::where('level', $level)->where('name', $r[$level])->where('parent_id', $parent)->first();
                        if (! $c) {
                            $c = $catalog->classification($user, ['name' => $r[$level], 'level' => $level, 'parent_id' => $parent]);
                        }$classification[$level.'_id'] = $c->id;
                        $parent = $c->id;
                    }
                    $matches = Product::where('name', $r['product_name'])->get();
                    if ($matches->count() > 1) {
                        throw ValidationException::withMessages(['product_name' => 'Ambiguous product name. Use the product editor.']);
                    }$product = $matches->first();
                    if ($product && isset($r['item_type']) && $product->item_type !== $r['item_type']) {
                        throw ValidationException::withMessages(['item_type' => 'Item type differs from the existing product. Use the product editor or a separate product name.']);
                    }
                    if (! $product) {
                        $product = $catalog->product($user, ['item_type' => $r['item_type'] ?? 'stock', 'name' => $r['product_name'], 'brand' => $r['brand'] ?: null, 'unit' => $r['unit'] ?: 'each', 'tax_class' => 'standard', 'warranty_months' => 0, 'attributes' => [], 'active' => true, ...$classification]);
                    }
                    $catalog->variant($user, $product, ['name' => $r['variant_name'], 'sku' => $r['sku'], 'barcode' => $r['barcode'] ?: null, 'attributes' => $attributes, 'cost_price' => $r['cost_price'], 'selling_price' => $r['selling_price'], 'wholesale_price' => $r['wholesale_price'] ?: '0', 'reorder_level' => $r['reorder_level'] ?: '0', 'minimum_stock' => '0', 'maximum_stock' => null, 'active' => true]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['file' => 'Row '.$entry['row'].': '.implode(' ', array_merge(...array_values($e->errors())))]);
                } catch (\JsonException $e) {
                    throw ValidationException::withMessages(['file' => 'Row '.$entry['row'].': invalid attributes JSON.']);
                }
            }
            Audit::record('catalog.imported', (string) count($rows));

            return count($rows);
        });
    }

    public static function safe(string $value): string
    {
        return preg_match('/^[=+@\-\t\r\n]/u', $value) ? "'".$value : $value;
    }

    public function export(User $user, bool $template = false)
    {
        InventoryAccess::permit($user, $template ? 'inventory.import' : 'inventory.export');

        return response()->streamDownload(function () use ($template, $user) {
            $context = app(TenantContext::class);
            $previous = $context->id;
            $context->id = $user->tenant_id;
            try {
                $out = fopen('php://output', 'w');
                fputcsv($out, [...self::HEADERS, 'item_type'], ',', '"', '');
                if (! $template) {
                    $classes = Classification::get()->keyBy('id');
                    foreach (ProductVariant::with('product')->lazyById(100) as $v) {
                        $p = $v->product;
                        $row = [$p->name, $v->name, $v->sku, $v->barcode ?? '', $classes->get($p->department_id)?->name ?? '', $classes->get($p->category_id)?->name ?? '', $classes->get($p->subcategory_id)?->name ?? '', $p->brand ?? '', $p->unit, $v->cost_price, $v->selling_price, $v->wholesale_price, $v->reorder_level, json_encode($v->attributes, JSON_THROW_ON_ERROR), $p->item_type];
                        fputcsv($out, array_map(fn ($v) => self::safe((string) $v), $row), ',', '"', '');
                    }
                }
                fclose($out);
            } finally {
                $context->id = $previous;
            }
        }, $template ? 'inventory-import-template.csv' : 'inventory-catalog.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
