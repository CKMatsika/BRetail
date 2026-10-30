<?php

namespace App\Http\Controllers;

use App\Models\BranchTransfer;
use App\Models\AccountingSetting;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ForeignAccount;
use App\Models\ForeignEntry;
use App\Models\ProductVariant;
use App\Models\TransferEvent;
use App\Models\Warehouse;
use App\Services\AccountingAccess;
use App\Services\AccountingLedger;
use App\Services\BranchTransfers;
use App\Services\CurrencyRates;
use App\Services\ForeignLedger;
use App\Services\InventoryAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class InternationalController
{
    public function data(Request $r): array
    {
        $u = $r->user();
        InventoryAccess::permit($u, 'currencies.view');
        $branches = AccountingAccess::branches($u)->get();
        $ids = $branches->pluck('id');
        $w = InventoryAccess::warehouses($u)->get();
        $book = AccountingAccess::book();
        $ledger = app(ForeignLedger::class);
        $accounts = ForeignAccount::whereIn('branch_id', $ids)->get()->map(fn ($a) => [...$a->toArray(), 'balance' => $ledger->balances($a, $book)]);
        $transfers = $u->canDo('transfers.view') ? BranchTransfer::where('book', $book)->whereIn('source_id', $w->pluck('id'))->whereIn('destination_id', $w->pluck('id'))->latest('id')->limit(200)->get() : collect();

        return ['base' => Company::firstOrFail()->base_currency, 'book' => $book, 'today' => AccountingLedger::today(), 'permissions' => $u->role->permissions, 'currencies' => Currency::get(), 'rates' => ExchangeRate::orderByDesc('effective_on')->get(), 'accounts' => $accounts, 'entries' => ForeignEntry::whereIn('foreign_account_id', $accounts->pluck('id'))->where('book', $book)->latest('id')->limit(200)->get(), 'offsets' => ChartAccount::where('active', true)->get(['id', 'code', 'name']), 'branches' => $branches, 'warehouses' => $w, 'transfers' => $transfers, 'events' => TransferEvent::whereIn('transfer_id', $transfers->pluck('id'))->get(), 'variants' => ProductVariant::where('active', true)->get(['id', 'sku', 'name'])];
    }

    public function page(Request $r)
    {
        if (! AccountingSetting::query()->exists()) {
            return redirect('/accounting')->with('success', 'Initialize the accounting chart and period before configuring currencies or branch transfers.');
        }

        return Inertia::render('International', ['initial' => $this->data($r)]);
    }

    public function index(Request $r)
    {
        return ['data' => $this->data($r)];
    }

    public function printTransfer(Request $r, int $id)
    {
        InventoryAccess::permit($r->user(), 'transfers.view');
        $transfer = BranchTransfer::where('book', AccountingAccess::book())->findOrFail($id);
        InventoryAccess::warehouse($r->user(), $transfer->source_id);
        InventoryAccess::warehouse($r->user(), $transfer->destination_id);

        return view('international.transfer', [
            'company' => Company::firstOrFail(),
            'transfer' => $transfer,
            'source' => Warehouse::findOrFail($transfer->source_id),
            'destination' => Warehouse::findOrFail($transfer->destination_id),
            'variants' => ProductVariant::whereIn('id', collect($transfer->items)->pluck('variant_id'))->get()->keyBy('id'),
            'events' => TransferEvent::where('transfer_id', $transfer->id)->orderBy('id')->get(),
        ]);
    }

    public function action(Request $r, string $action, ?string $id = null)
    {
        $u = $r->user();
        $d = $r->all();
        $result = match ($action) {
            'currencies' => app(CurrencyRates::class)->currency($u, $d),'rates' => app(CurrencyRates::class)->propose($u, $d),'approve-rate' => app(CurrencyRates::class)->approve($u, (int) $id),'accounts' => app(ForeignLedger::class)->account($u, $d),'entries' => app(ForeignLedger::class)->post($u, (int) $id, $d),'revalue' => app(ForeignLedger::class)->revalue($u, (int) $id, $d),'reverse' => app(ForeignLedger::class)->reverseRevaluation($u, (int) $id, $d),'transfers' => app(BranchTransfers::class)->create($u, $d),'approve-transfer' => app(BranchTransfers::class)->transition($u, (int) $id, 'approve'),'cancel-transfer' => app(BranchTransfers::class)->transition($u, (int) $id, 'cancel'),'dispatch' => app(BranchTransfers::class)->post($u, (int) $id, 'dispatch', $d),'loss' => app(BranchTransfers::class)->post($u, (int) $id, 'loss', $d), 'receive' => app(BranchTransfers::class)->post($u,(int) $id,'receive',$d),default => abort(404)
        };

        return ['data' => $result];
    }
}
