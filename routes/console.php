<?php

use App\Models\User;
use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
