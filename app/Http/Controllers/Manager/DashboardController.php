<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        return view('manager.dashboard', [
            'user' => $user,
        ]);
    }
}
