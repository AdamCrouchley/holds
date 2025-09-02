<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PortalAuthController extends Controller
{
    /** Show the login page */
    public function show()
    {
        return view('portal.login'); // your styled Blade view
    }

    /** Handle login request (email + optional reference) */
    public function attempt(Request $request)
    {
        $data = $request->validate([
            'email'     => ['required','email'],
            'reference' => ['nullable','string','max:64'],
        ]);

        // TODO: send magic code / link here.
        // For now, just flash a success message and reload page.
        return back()->with('status', 'If that email exists, we\'ve sent a one-time code.');
    }
}
