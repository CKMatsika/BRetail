<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\TenantModule;
use App\Services\ReportsAnalytics;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ReportsController
{
    public function page(Request $request, ReportsAnalytics $reports)
    {
        $data = $reports->run($request->user(), $request->only('from', 'to', 'branch_id', 'book'));
        $branches = Branch::whereIn('id', collect($data['branches'])->pluck('id'))->with('warehouses')->get();

        return Inertia::render('Workspace', [
            'section' => 'reports',
            'analytics' => $data,
            'user' => $request->user()->only('id', 'name', 'email'),
            'role' => $request->user()->role->name,
            'permissions' => $request->user()->role->permissions,
            'company' => [...Company::firstOrFail()->only('name', 'industry', 'base_currency', 'accepted_currencies', 'logo_url'), 'settings' => []],
            'branches' => $branches,
            'modules' => TenantModule::get()->map(fn ($module) => [...$module->toArray(), ...config('modules.'.$module->key)]),
        ]);
    }

    public function export(Request $request, ReportsAnalytics $reports)
    {
        abort_unless($request->user()->canDo('reports.export'), 403);
        $data = $reports->run($request->user(), $request->only('from', 'to', 'branch_id', 'book'));
        $report = $request->input('report', 'overview');
        abort_unless(in_array($report, ['overview', 'pos_sales', 'sales', 'procurement', 'debtors', 'creditors', 'profit_loss', 'balance_sheet', 'trial_balance', 'general_ledger', 'cash_flow'], true), 422);
        $rows = [['Company', $data['company_name']], ['Report', $report], ['From', $data['filters']['from']], ['Through / as at', $data['filters']['to']], ['Book', $data['filters']['book']], ['Base currency', $data['base_currency']], ['Branch', $data['filters']['branch_id'] ?? 'All authorised branches'], []];
        if (in_array($report, ['overview', 'pos_sales'], true)) {
            $rows[] = ['Currency', 'Transactions', 'Gross POS sales', 'Refunds', 'Net POS total', 'Net VAT'];
            foreach ($data['currency_totals'] as $r) {
                $rows[] = [$r['currency'], $r['count'], $r['sales'], $r['refunds'], $r['net'], $r['tax']];
            }
            $rows[] = [];
            $rows[] = ['Business date', 'POS transactions'];
            foreach ($data['daily_sales'] as $r) {
                $rows[] = [$r['date'], $r['transactions']];
            }
        } elseif (in_array($report, ['sales', 'procurement'], true)) {
            $business = $data['business'][$report];
            abort_unless($business, 403);
            $rows[] = ['Date', 'Document', 'Type', 'Party', 'Reference', 'Net (base)', 'VAT (base)', 'Total (base)', 'Original currency', 'Original total'];
            foreach ($business['documents'] as $r) {
                $signed = fn ($value) => (string) BigDecimal::of($value)->multipliedBy($r['sign']);
                $rows[] = [$r['date'], $r['number'], $r['kind'], $r['party_name'], $r['reference'], $signed($r['net']), $signed($r['tax']), $signed($r['total']), $r['currency'], $r['original_total'] !== null ? $signed($r['original_total']) : ''];
            }
            $rows[] = [];
            $rows[] = [$report === 'sales' ? 'Customer summary' : 'Supplier summary', 'Net (base)', 'VAT (base)', 'Total (base)'];
            foreach ($business['by_party'] as $r) {
                $rows[] = [$r['name'], $r['net'], $r['tax'], $r['total']];
            }
            if ($report === 'procurement') {
                $rows[] = [];
                $rows[] = ['Purchase orders (current status)', 'Supplier', 'Date', 'Expected', 'Current status', 'Base value'];
                foreach ($business['orders'] as $r) {
                    $rows[] = [$r['number'], $r['supplier_name'], $r['date'], $r['expected_on'], $r['status'], $r['total']];
                }
            }
        } elseif (in_array($report, ['debtors', 'creditors'], true)) {
            $aging = $data['business']['aging'];
            $role = $report === 'debtors' ? 'receivable' : 'payable';
            abort_unless($aging && collect($aging['controls'])->contains('role', $role), 403);
            $columns = ['current', '1–30', '31–60', '61–90', '90+', 'total'];
            $rows[] = ['Party', 'Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days', 'Total'];
            foreach ($aging['parties'] as $r) {
                if ($r['role'] === $role) {
                    $rows[] = [$r['party_name'], ...array_map(fn ($k) => $r[$k], $columns)];
                }
            }
            $rows[] = ['Total', ...array_map(fn ($k) => $aging['totals'][$role][$k], $columns)];
            $rows[] = [];
            $rows[] = ['Document', 'Party', 'Branch', 'Issue date', 'Due date', 'Days overdue', 'Age band', 'Outstanding (base)', 'Original currency', 'Original outstanding'];
            foreach ($aging['aging'] as $r) {
                if ($r['role'] === $role) {
                    $rows[] = [$r['number'], $r['party_name'], $r['branch_name'], $r['date'], $r['due_on'], $r['days_overdue'], $r['bucket'], $r['outstanding'], $r['original_currency'], $r['original_outstanding']];
                }
            }
            $rows[] = [];
            $rows[] = ['Control reconciliation', 'Ledger', 'Subledger', 'Difference'];
            foreach ($aging['controls'] as $r) {
                if ($r['role'] === $role) {
                    $rows[] = [$r['branch_name'], $r['ledger'], $r['subledger'], $r['unallocated']];
                }
            }
        } else {
            $financial = $data['accounting'];
            abort_unless($financial, 403);
            if ($report === 'trial_balance') {
                $rows[] = ['Code', 'Account', 'Opening Dr/(Cr)', 'Period debit', 'Period credit', 'Closing Dr/(Cr)'];
                foreach ($financial['trial_balance'] as $r) {
                    $rows[] = [$r['code'], $r['name'], $r['opening'], $r['debit'], $r['credit'], $r['closing']];
                }
            } elseif ($report === 'general_ledger') {
                $rows[] = ['Date', 'Journal', 'Account', 'Reference', 'Description', 'Debit', 'Credit'];
                foreach ($financial['general_ledger'] as $r) {
                    $rows[] = [$r['date'], $r['journal'], $r['account'], $r['reference'], $r['description'], $r['debit'], $r['credit']];
                }
            } elseif (in_array($report, ['profit_loss', 'balance_sheet'], true)) {
                $rows[] = ['Code', 'Account', 'Type', 'Amount'];
                foreach ($financial['trial_balance'] as $r) {
                    if ($report === 'profit_loss' && in_array($r['type'], ['income', 'expense'], true)) {
                        $value = BigDecimal::of($r['debit'])->minus($r['credit']);
                        $rows[] = [$r['code'], $r['name'], $r['type'], (string) ($r['type'] === 'income' ? $value->negated() : $value)];
                    } elseif ($report === 'balance_sheet' && in_array($r['type'], ['asset', 'liability', 'equity'], true)) {
                        $value = BigDecimal::of($r['closing']);
                        $rows[] = [$r['code'], $r['name'], $r['type'], (string) ($r['type'] === 'asset' ? $value : $value->negated())];
                    }
                }
                $rows[] = [];
                foreach ($financial[$report] as $k => $v) {
                    $rows[] = [str_replace('_', ' ', $k), $v];
                }
            } else {
                $rows[] = ['Cash flow', 'Amount'];
                foreach ($financial['cash_flow'] as $k => $v) {
                    if (is_array($v)) {
                        foreach ($v as $category => $value) {
                            $rows[] = [$category, $value];
                        }
                    } else {
                        $rows[] = [str_replace('_', ' ', $k), $v];
                    }
                }
            }
        }

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            foreach ($rows as $row) {
                $safe = array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) && ! preg_match('/^-?\d+(\.\d+)?$/D', $value) ? "'".$value : $value, $row);
                fputcsv($output, $safe, ',', '"', '');
            }
            fclose($output);
        }, 'bretail-'.$report.'-'.$data['filters']['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
