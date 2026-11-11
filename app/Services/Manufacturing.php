<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeLine;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Manufacturing
{
    public function saveRecipe(User $user, array $input, ?ProductionRecipe $recipe = null): ProductionRecipe
    {
        InventoryAccess::permit($user, 'manufacturing.manage');
        $d = validator($input, [
            'name' => 'required|string|max:160', 'output_variant_id' => 'required|integer',
            'batch_quantity' => ['required','numeric','gt:0','max:999999999'], 'active' => 'required|boolean',
            'lines' => 'required|array|min:1|max:100', 'lines.*.variant_id' => 'required|integer|distinct',
            'lines.*.quantity' => ['required','numeric','gt:0','max:999999999'],
        ])->validate();
        $output = ProductVariant::with('product')->findOrFail($d['output_variant_id']);
        abort_unless($output->active && $output->product->active && $output->product->item_type === 'stock', 422, 'The finished item must be an active stock item.');
        abort_if(collect($d['lines'])->contains(fn ($line) => (int)$line['variant_id'] === (int)$output->id), 422, 'The finished item cannot also be a component.');
        foreach ($d['lines'] as $line) {
            $component = ProductVariant::with('product')->findOrFail($line['variant_id']);
            abort_unless($component->active && $component->product->active && $component->product->item_type === 'stock', 422, 'Recipe components must be active stock items.');
        }
        return DB::transaction(function () use ($d, $recipe) {
            if ($recipe) {
                if (ProductionOrder::where('recipe_id', $recipe->id)->whereIn('status', ['planned','completed'])->exists()) {
                    throw ValidationException::withMessages(['recipe' => 'This recipe has production history and cannot be edited. Create a new recipe revision instead.']);
                }
                $recipe->update(collect($d)->only('name','output_variant_id','batch_quantity','active')->all());
                $recipe->lines()->delete();
            } else {
                $recipe = ProductionRecipe::create(collect($d)->only('name','output_variant_id','batch_quantity','active')->all());
            }
            foreach ($d['lines'] as $line) ProductionRecipeLine::create(['recipe_id'=>$recipe->id,'variant_id'=>$line['variant_id'],'quantity'=>$line['quantity']]);
            Audit::record('manufacturing.recipe_saved', (string)$recipe->id, ['output_variant_id'=>$recipe->output_variant_id]);
            return $recipe->load('lines');
        });
    }

    public function createOrder(User $user, array $input): ProductionOrder
    {
        InventoryAccess::permit($user, 'manufacturing.manage');
        $d = validator($input, ['recipe_id'=>'required|integer','warehouse_id'=>'required|integer','quantity'=>['required','numeric','gt:0','max:999999999'],'notes'=>'nullable|string|max:2000'])->validate();
        InventoryAccess::warehouse($user, (int)$d['warehouse_id']);
        $recipe = ProductionRecipe::with('lines')->where('active', true)->findOrFail($d['recipe_id']);
        abort_if($recipe->lines->isEmpty(), 422, 'The selected recipe has no components.');
        do { $reference = 'MO-'.now()->format('ymd').'-'.Str::upper(Str::random(6)); } while (ProductionOrder::where('reference',$reference)->exists());
        return ProductionOrder::create(['recipe_id'=>$recipe->id,'warehouse_id'=>$d['warehouse_id'],'reference'=>$reference,'quantity'=>$d['quantity'],'status'=>'planned','notes'=>$d['notes']??null,'created_by'=>$user->id]);
    }

    public function complete(User $user, ProductionOrder $order): void
    {
        InventoryAccess::permit($user, 'manufacturing.produce');
        DB::transaction(function () use ($user, $order) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            abort_unless(TenantModule::where('key','manufacturing')->where('enabled',true)->exists(),403,'Manufacturing is disabled.');
            $order = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'planned', 422, 'Only planned production orders can be completed.');
            InventoryAccess::warehouse($user, $order->warehouse_id);
            $recipe = ProductionRecipe::with('lines')->findOrFail($order->recipe_id);
            $output = ProductVariant::with('product')->findOrFail($recipe->output_variant_id);
            $factor = BigDecimal::of($order->quantity)->dividedBy($recipe->batch_quantity, 6, RoundingMode::HalfUp);
            $total = BigDecimal::zero(); $movements = [];
            foreach ($recipe->lines as $line) {
                $required = BigDecimal::of($line->quantity)->multipliedBy($factor)->toScale(3, RoundingMode::Ceiling);
                $variant = ProductVariant::with('product')->findOrFail($line->variant_id);
                abort_unless($variant->active && $variant->product->active, 422, 'Inactive recipe component '.$variant->sku.' must be replaced.');
                $balance = StockBalance::where('warehouse_id',$order->warehouse_id)->where('variant_id',$variant->id)->lockForUpdate()->first();
                if (! $balance || BigDecimal::of($balance->quantity)->isLessThan($required)) {
                    throw ValidationException::withMessages(['stock'=>'Insufficient stock for component '.$variant->sku.'.']);
                }
                $unitCost = BigDecimal::of($balance->average_cost);
                $value = $required->multipliedBy($unitCost)->toScale(4,RoundingMode::HalfUp);
                $newQty = BigDecimal::of($balance->quantity)->minus($required);
                $newValue = BigDecimal::of($balance->value)->minus($value);
                if ($newValue->isNegative()) $newValue = BigDecimal::zero();
                $newValue = $newValue->toScale(4);
                if ($newQty->isZero()) $newValue = BigDecimal::zero();
                $balance->update(['quantity'=>(string)$newQty,'value'=>(string)$newValue,'average_cost'=>$newQty->isZero()?'0.0000':(string)$newValue->dividedBy($newQty,4,RoundingMode::HalfUp)]);
                $total = $total->plus($value);
                $movements[] = [$variant->id, $required->negated(), $unitCost, $value->negated()];
                ProductionOrderLine::create(['order_id'=>$order->id,'variant_id'=>$variant->id,'quantity'=>$required,'unit_cost'=>$unitCost,'value'=>$value]);
            }
            $finishedCost = $total->dividedBy(BigDecimal::of($order->quantity),4,RoundingMode::HalfUp);
            $outBalance = StockBalance::firstOrCreate(['warehouse_id'=>$order->warehouse_id,'variant_id'=>$output->id],['quantity'=>'0.000','value'=>'0.0000','average_cost'=>'0.0000']);
            $outBalance = StockBalance::whereKey($outBalance->id)->lockForUpdate()->firstOrFail();
            $receivedValue = $total->toScale(4,RoundingMode::HalfUp);
            $newOutQty = BigDecimal::of($outBalance->quantity)->plus($order->quantity);
            $newOutValue = BigDecimal::of($outBalance->value)->plus($receivedValue);
            $outBalance->update(['quantity'=>(string)$newOutQty,'value'=>(string)$newOutValue,'average_cost'=>(string)$newOutValue->dividedBy($newOutQty,4,RoundingMode::HalfUp)]);
            $movements[] = [$output->id, BigDecimal::of($order->quantity), $finishedCost, $receivedValue];

            $issue = $this->document($user,$order,'production_issue','ISSUE',$movements);
            $receipt = $this->document($user,$order,'production_receipt','RECEIPT',array_slice($movements,-1));
            $order->update(['status'=>'completed','completed_by'=>$user->id,'material_cost'=>(string)$total,'completed_at'=>now()]);
            Audit::record('manufacturing.order_completed',(string)$order->id,['issue_document'=>$issue->id,'receipt_document'=>$receipt->id,'material_cost'=>(string)$total]);
        },3);
    }

    private function document(User $user, ProductionOrder $order, string $type, string $suffix, array $movements): StockDocument
    {
        $doc = StockDocument::create(['request_id'=>(string)Str::uuid(),'payload_hash'=>hash('sha256',$order->id.'|'.$type),'type'=>$type,'warehouse_id'=>$order->warehouse_id,'actor_id'=>$user->id,'reference'=>$order->reference.'-'.$suffix,'reason'=>'Manufacturing order '.$order->reference,'created_at'=>now()]);
        foreach ($movements as [$variantId,$quantity,$unitCost,$value]) {
            if ($type === 'production_issue' && BigDecimal::of($quantity)->isPositive()) continue;
            StockMovement::create(['document_id'=>$doc->id,'variant_id'=>$variantId,'warehouse_id'=>$order->warehouse_id,'quantity'=>(string)$quantity,'unit_cost'=>(string)$unitCost,'value'=>(string)$value,'created_at'=>now()]);
        }
        return $doc;
    }

    public function cancel(User $user, ProductionOrder $order): void
    {
        InventoryAccess::warehouse($user, $order->warehouse_id);
        abort_unless($order->status === 'planned',422,'Only planned production orders can be cancelled.');
        $order->update(['status'=>'cancelled']);
        Audit::record('manufacturing.order_cancelled',(string)$order->id);
    }
}
