<?php

use App\Models\AccountMapping;
use App\Models\AccountingSetting;
use App\Models\ChartAccount;
use App\Services\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $accounts = [
        ['5600', 'Payroll expense', 'expense', 'payroll_expense'],
        ['5610', 'Employer social security expense', 'expense', 'payroll_nssa_expense'],
        ['2200', 'Net wages payable', 'liability', 'payroll_net_payable'],
        ['2210', 'PAYE and AIDS levy payable', 'liability', 'payroll_paye_payable'],
        ['2220', 'NSSA payable', 'liability', 'payroll_nssa_payable'],
        ['2230', 'Other payroll deductions payable', 'liability', 'payroll_other_deductions'],
    ];

    public function up(): void
    {
        Schema::create('payroll_run_postings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('payroll_run_id');
            $t->unsignedBigInteger('journal_id');
            $t->unsignedBigInteger('reverses_posting_id')->nullable();
            $t->string('book', 12);
            $t->string('kind', 12);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->date('date');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'payroll_run_id'])->references(['tenant_id', 'id'])->on('payroll_runs');
            $t->foreign(['tenant_id', 'journal_id'])->references(['tenant_id', 'id'])->on('journal_batches');
            $t->foreign(['tenant_id', 'reverses_posting_id'])->references(['tenant_id', 'id'])->on('payroll_run_postings');
            $t->unique(['tenant_id', 'payroll_run_id', 'book', 'kind']);
        });
        foreach (DB::table('accounting_settings')->get(['tenant_id']) as $setting) {
            app(TenantContext::class)->id = $setting->tenant_id;
            DB::transaction(function () {
                foreach ($this->accounts as [$code, $name, $type, $role]) {
                    if (AccountMapping::where('role', $role)->exists()) continue;
                    $account = ChartAccount::where('code', $code)->first();
                    if (! $account) $account = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'report_group' => $type, 'is_cash' => false]);
                    if ($account->type !== $type || $account->is_cash) throw new RuntimeException("Payroll account code $code conflicts with an existing chart account.");
                    AccountMapping::create(['role' => $role, 'account_id' => $account->id]);
                }
            });
        }
        app(TenantContext::class)->id = null;
    }

    public function down(): void
    {
        if (DB::table('payroll_run_postings')->exists()) throw new RuntimeException('Payroll journal history must be preserved.');
        Schema::dropIfExists('payroll_run_postings');
    }
};
