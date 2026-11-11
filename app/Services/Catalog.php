<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Classification;
use App\Models\Company;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TradeDocument;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Catalog
{
    public const MONEY = 'regex:/^\d{1,9}(\.\d{1,4})?$/';

    public const QUANTITY = 'regex:/^\d{1,9}(\.\d{1,3})?$/';

    public function classification(User $user, array $input): Classification
    {
        InventoryAccess::permit($user, 'inventory.configure');
        $d = Validator::make($input, ['name' => 'required|string|max:120', 'level' => ['required', Rule::in(['department', 'category', 'subcategory'])], 'parent_id' => 'nullable|integer'])->validate();

        return DB::transaction(function () use ($d) {
            $this->lock();
            $parent = isset($d['parent_id']) ? Classification::findOrFail($d['parent_id']) : null;
            $required = ['department' => null, 'category' => 'department', 'subcategory' => 'category'][$d['level']];
            if (($parent?->level) !== $required) {
                throw ValidationException::withMessages(['parent_id' => 'Choose the correct parent classification.']);
            }
            if (Classification::where('level', $d['level'])->where('parent_id', $parent?->id)->where('name', $d['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'This classification already exists.']);
            }
            $c = Classification::create($d);
            Audit::record('classification.created', (string) $c->id);

            return $c;
        });
    }

    public function product(User $user, array $input, ?Product $product = null): Product
    {
        InventoryAccess::permit($user, 'inventory.configure');
        $d = Validator::make($input, ['item_type' => ['sometimes', 'required', Rule::in(['stock', 'service'])], 'name' => 'required|string|max:160', 'description' => 'nullable|string|max:5000', 'department_id' => 'nullable|integer', 'category_id' => 'nullable|integer', 'subcategory_id' => 'nullable|integer', 'brand' => 'nullable|string|max:120', 'unit' => 'required|string|max:30', 'tax_class' => 'required|string|max:60', 'warranty_months' => 'required|integer|between:0,240', 'attributes' => 'present|array|max:30', 'attributes.*' => 'nullable|string|max:255', 'active' => 'required|boolean'])->validate();
        if (! in_array($d['tax_class'], ['standard', 'zero', 'exempt'], true) && ! collect(Company::firstOrFail()->settings['tax_rates'] ?? [])->contains(fn ($tax) => ($tax['id'] ?? null) === $d['tax_class'])) {
            throw ValidationException::withMessages(['tax_class' => 'Choose a tax profile configured in Company settings.']);
        }

        return DB::transaction(function () use ($d, $product) {
            $this->lock();
            $previous = null;
            foreach (['department', 'category', 'subcategory'] as $level) {
                $id = $d[$level.'_id'] ?? null;
                $c = $id ? Classification::findOrFail($id) : null;
                if ($c && ($c->level !== $level || $c->parent_id !== $previous)) {
                    throw ValidationException::withMessages([$level.'_id' => 'Classification hierarchy is inconsistent.']);
                }$previous = $c?->id;
            }
            $p = $product ?? new Product;
            $product?->refresh();
            $itemType = $d['item_type'] ?? $product?->item_type ?? 'stock';
            if ($product && $product->item_type !== $itemType) {
                $ids = $product->variants()->pluck('id');
                $used = StockMovement::whereIn('variant_id', $ids)->exists()
                    || PosSaleItem::whereIn('variant_id', $ids)->exists()
                    || StockCountLine::whereIn('variant_id', $ids)->exists()
                    || TradeDocument::where('kind', 'invoice')->get(['items'])->contains(fn ($doc) => collect($doc->items)->contains(fn ($i) => $ids->contains($i['variant_id'])))
                    || PurchaseOrder::get(['items'])->contains(fn ($doc) => collect($doc->items)->contains(fn ($i) => $ids->contains($i['variant_id'])));
                if ($used) {
                    throw ValidationException::withMessages(['item_type' => 'This item already has stock, orders, counts or posted sales. Create a separate service or physical item to preserve its history.']);
                }
            }
            $d['item_type'] = $itemType;
            $p->fill($d);
            $p->save();
            Audit::record($product ? 'product.updated' : 'product.created', (string) $p->id);

            return $p;
        });
    }

    public function variant(User $user, Product $product, array $input, ?ProductVariant $variant = null): ProductVariant
    {
        InventoryAccess::permit($user, 'inventory.configure');
        $unique = fn ($column) => Rule::unique('product_variants', $column)->where('tenant_id', $user->tenant_id)->ignore($variant?->id);
        $d = Validator::make($input, ['name' => 'required|string|max:160', 'sku' => ['required', 'string', 'max:80', $unique('sku')], 'barcode' => ['nullable', 'string', 'max:80', $unique('barcode')], 'attributes' => 'present|array|max:30', 'attributes.*' => 'nullable|string|max:255', 'cost_price' => ['required', 'string', self::MONEY], 'selling_price' => ['required', 'string', self::MONEY], 'wholesale_price' => ['required', 'string', self::MONEY], 'reorder_level' => ['required', 'string', self::QUANTITY], 'minimum_stock' => ['required', 'string', self::QUANTITY], 'maximum_stock' => ['nullable', 'string', self::QUANTITY], 'active' => 'required|boolean'])->validate();
        if (isset($d['maximum_stock']) && BigDecimal::of($d['maximum_stock'])->isLessThan($d['minimum_stock'])) {
            throw ValidationException::withMessages(['maximum_stock' => 'Maximum must be at least minimum stock.']);
        }

        return DB::transaction(function () use ($d, $product, $variant) {
            $this->lock();
            foreach (['sku', 'barcode'] as $field) {
                if (! empty($d[$field]) && ProductVariant::where($field, $d[$field])->when($variant, fn ($q) => $q->where('id', '!=', $variant->id))->exists()) {
                    throw ValidationException::withMessages([$field => 'This '.$field.' is already in use.']);
                }
            }
            if ($variant && $variant->product_id !== $product->id) {
                abort(404);
            }$v = $variant ?? new ProductVariant;
            $v->fill([...$d, 'product_id' => $product->id]);
            $v->save();
            Audit::record($variant ? 'variant.updated' : 'variant.created', (string) $v->id);

            return $v;
        });
    }

    public function warehouse(User $user, array $input): Warehouse
    {
        InventoryAccess::permit($user, 'inventory.configure');
        $d = Validator::make($input, ['name' => 'required|string|max:120', 'branch_id' => 'required|integer'])->validate();

        return DB::transaction(function () use ($user, $d) {
            $this->lock();
            $branch = Branch::findOrFail($d['branch_id']);
            abort_unless($user->role->company_wide || $user->branches()->where('branches.id', $branch->id)->exists(), 403);
            abort_unless($branch->active, 422, 'Branch is inactive.');
            if (Warehouse::where('branch_id', $branch->id)->where('name', $d['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'Warehouse name already exists in this branch.']);
            }$w = Warehouse::create($d);
            Audit::record('warehouse.created', (string) $w->id);

            return $w;
        });
    }

    public function lock(): void
    {
        Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
        abort_unless(TenantModule::where('key', 'inventory')->where('enabled', true)->exists(), 403, 'Inventory is disabled.');
    }
}
