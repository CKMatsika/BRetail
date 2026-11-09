<?php

use App\Models\User;
use App\Models\PlatformStaff;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('retail:demo {--email=owner@bretail.test}', function () {
    if (! app()->environment('local')) {
        $this->error('Demo companies can only be created in the local environment.');

        return 1;
    }
    $email = $this->option('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
        $this->error('Choose a valid, unused email address.');

        return 1;
    }
    $password = Str::password(20);
    try {
        app(Onboarding::class)->register([
            'name' => 'Alex Moyo', 'email' => $email, 'password' => $password,
            'company_name' => 'Little Steps Retail', 'industry' => 'apparel',
            'base_currency' => 'USD', 'accepted_currencies' => ['ZWG', 'ZAR'],
            'branch_name' => 'Harare', 'branch_code' => 'HRE', 'warehouse_name' => 'Harare warehouse',
            'address' => 'Harare, Zimbabwe',
        ]);
        $this->info('Demo workspace created. Email: '.$email);
        $this->line('Generated password: '.$password);
        $this->comment('Store this password privately. No retail transactions were seeded.');
    } finally {
        app(TenantContext::class)->id = null;
    }
})->purpose('Create a local demonstration company with a generated password');

Artisan::command('platform:staff {email} {--revoke}', function (string $email) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error('No user exists with that email address. Create and verify the account first.');

        return 1;
    }
    if ($this->option('revoke')) {
        PlatformStaff::where('user_id', $user->id)->delete();
        $action = 'platform.staff_revoked';
        $this->info('Platform access revoked for '.$email.'.');
    } else {
        PlatformStaff::updateOrCreate(['user_id' => $user->id], ['active' => true, 'granted_at' => now()]);
        $action = 'platform.staff_granted';
        $this->info('Platform access granted to '.$email.'. They can sign in to open organization management.');
    }
    DB::table('platform_audit_events')->insert([
        'actor_user_id' => null,
        'tenant_id' => $user->tenant_id,
        'action' => $action,
        'reason' => 'Platform staff access changed from the Artisan command.',
        'details' => json_encode(['email' => $email, 'source' => 'artisan'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    return 0;
})->purpose('Grant or revoke access to the BRetail platform administration console');

Artisan::command('tenders:check-deadlines {--tenant=}', function () {
    $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
    $count = app(\App\Services\Tender\TenderDeadlineEngineService::class)->checkAndDispatchAlerts($tenantId);
    $this->info("Tender milestone scan completed. {$count} deadline alert(s) dispatched.");

    return 0;
})->purpose('Scan tender milestones and generate reminders across configurable lead time thresholds');
