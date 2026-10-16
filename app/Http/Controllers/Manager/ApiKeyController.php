<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function index(): View
    {
        return view('manager.api-keys.index', [
            'apiKeys' => ApiKey::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(): RedirectResponse
    {
        ApiKey::issue();

        return redirect()->route('manager.api-keys.index');
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return redirect()->route('manager.api-keys.index');
    }
}
