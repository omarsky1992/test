<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeeHome;
use App\Support\Themes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A user's own colour, and the admin's switch between the admin and the employee interface.
 */
class InterfaceController extends Controller
{
    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate(['color' => ['required', Rule::in(array_keys(Themes::ALL))]]);
        $request->user()->forceFill(['theme_color' => $data['color']])->save();

        return back();
    }

    public function mode(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);
        $mode = $user->ui_mode === 'employee' ? 'admin' : 'employee';
        $user->forceFill(['ui_mode' => $mode])->save();

        return redirect($mode === 'employee' ? EmployeeHome::getUrl() : Dashboard::getUrl());
    }
}
