<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\TenantModule;
use App\Services\ReportsAnalytics;
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
        $rows = [['Date', 'Transactions', 'Sales currency', 'Sales', 'Refunds', 'Net sales']];
        foreach ($data['currency_totals'] as $currency) {
            $rows[] = [$data['filters']['from'].' to '.$data['filters']['to'], $currency['count'], $currency['currency'], $currency['sales'], $currency['refunds'], $currency['net']];
        }
        foreach ($data['daily_sales'] as $day) {
            $rows[] = [$day['date'], $day['transactions'], '', '', '', ''];
        }

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            foreach ($rows as $row) {
                $safe = array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row);
                fputcsv($output, $safe);
            }
            fclose($output);
        }, 'bretail-analytics.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
