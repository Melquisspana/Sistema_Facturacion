<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // El observer de User ya cerró las otras sesiones de esta cuenta; acá queda el rastro.
        activity('acceso')
            ->causedBy($request->user())
            ->performedOn($request->user())
            ->withProperties(['ip' => $request->ip()])
            ->log('Cambió su propia contraseña');

        return back()->with('status', 'password-updated');
    }
}
