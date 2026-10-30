<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $r): array
    {
        return [...parent::share($r), 'flash' => ['success' => fn () => $r->session()->get('success')]];
    }
}
