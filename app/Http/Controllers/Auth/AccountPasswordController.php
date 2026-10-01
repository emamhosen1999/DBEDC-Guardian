<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Change one's own password. The forced-change flow (users.must_change_password) lands here.
 */
class AccountPasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Auth/ChangePassword', [
            'title' => 'Change your password',
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'password.different' => 'Your new password must be different from your current password.',
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            return back()->withErrors(['current_password' => 'The provided password does not match your current password.']);
        }

        $user->forceFill(['password' => $validated['password'], 'must_change_password' => false])->save();

        return redirect()->intended(route('dashboard'))->with('status', 'Password changed.');
    }
}
