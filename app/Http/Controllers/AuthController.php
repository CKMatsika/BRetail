<?php

namespace App\Http\Controllers;

use App\Services\Onboarding;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController
{
    public function loginPage()
    {
        return Inertia::render('Auth', ['mode' => 'login']);
    }

    public function registerPage()
    {
        return Inertia::render('Auth', ['mode' => 'register', 'templates' => config('retail.templates'), 'modules' => config('modules')]);
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$data, 'active' => true], $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The credentials are incorrect or this account is inactive.']);
        }
        $r->session()->regenerate();

        return redirect('/dashboard');
    }

    public function register(Request $r, Onboarding $service)
    {
        $data = $r->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'company_name' => 'required|string|max:160', 'industry' => ['required', Rule::in(array_keys(config('retail.templates')))],
            'base_currency' => 'required|string|regex:/^[A-Z]{3}$/', 'accepted_currencies' => 'array|max:10', 'accepted_currencies.*' => 'string|regex:/^[A-Z]{3}$/',
            'branch_name' => 'required|string|max:120', 'branch_code' => 'required|alpha_dash|max:20', 'warehouse_name' => 'required|string|max:120',
            'registration' => 'nullable|string|max:120', 'tax_id' => 'nullable|string|max:120', 'contact_email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:255',
            'financial_year_start' => 'required|date_format:m-d', 'tax_label' => 'required|string|max:40', 'tax_rate' => 'required|decimal:0,4|between:0,100',
            'receipt_footer' => 'nullable|string|max:255', 'invoice_prefix' => 'required|alpha_dash|max:20', 'preferred_modules' => 'array', 'preferred_modules.*' => ['string', Rule::in(array_keys(config('modules')))],
        ]);
        try {
            $user = $service->register($data);
            Auth::login($user);
            $r->session()->regenerate();

            return redirect('/dashboard');
        } finally {
            app(TenantContext::class)->id = null;
        }
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
