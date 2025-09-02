<?php

namespace App\Http\Controllers;

use App\Mail\CustomerPortalLink;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CustomerPortalController extends Controller
{
    /** Show the “send me a link” form. */
    public function showRequestForm()
    {
        return view('portal.login'); // resources/views/portal/login.blade.php
    }

    /** POST: send a magic link to the provided email. */
    public function sendLink(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'redirect' => ['nullable', 'string'],
        ]);

        $customer = Customer::findByEmail($data['email']);

        // For privacy, don't reveal whether the email exists.
        // If not found, pretend success.
        if (!$customer) {
            return back()->with('status', 'If that email exists, a link has been sent.');
        }

        // Optionally store intended redirect on the customer (or use query param only)
        if (!empty($data['redirect'])) {
            $customer->forceFill(['portal_magic_redirect' => $data['redirect']])->save();
        }

        // Build a one-time login URL
        $url = $customer->buildMagicLoginUrl('portal.login.consume');

        // Send mail
        Mail::to($customer->email)->send(new CustomerPortalLink($customer, $url));

        return back()->with('status', 'If that email exists, a link has been sent.');
    }

    /**
     * GET: consume magic link.
     * Expects ?token=...&email=...
     */
    public function consume(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        $customer = Customer::findByEmail($request->string('email'));
        if (!$customer || !$customer->consumeLoginToken($request->string('token'))) {
            throw ValidationException::withMessages([
                'token' => 'This login link is invalid or has expired.',
            ]);
        }

        // Log the customer into the 'customer' guard
        Auth::guard('customer')->login($customer);

        // Compute redirect
        $intended = $request->string('intended') ?: $customer->portal_magic_redirect ?: route('portal.home');

        // Optionally clear the stored redirect so it doesn't stick around
        $customer->forceFill(['portal_magic_redirect' => null])->save();

        return redirect($intended);
    }

    /** Simple dashboard route (requires auth:customer) */
    public function dashboard()
    {
        $customer = Auth::guard('customer')->user();
        return view('customer.dashboard', compact('customer'));
    }

    /** POST: logout (auth:customer) */
    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
