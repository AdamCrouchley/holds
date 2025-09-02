<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    /** Show login (email + reference). */
    public function showLogin()
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('customer.bookings');
        }

        return view('customer.auth.login');
    }

    /** Handle login using (email, reservation reference). */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'     => ['required', 'email'],
            'reference' => ['required', 'string', 'max:191'],
        ]);

        $email = strtolower(trim($data['email']));
        $raw   = trim($data['reference']);

        // Normalise: upper, strip spaces, collapse dashes
        $needleExact  = strtoupper($raw);
        $needleLoose  = str_replace([' ', '-'], '', $needleExact);

        // Try to find by reference (exact or dashless) or by VEVS booking_id if numeric
        $booking = \App\Models\Booking::query()
            ->when(true, function ($q) use ($needleExact, $needleLoose) {
                // reference = 'QW1756...' or 'BK-...'
                $q->where(function ($qq) use ($needleExact, $needleLoose) {
                    $qq->where('reference', $needleExact)
                       ->orWhereRaw("REPLACE(reference, '-', '') = ?", [$needleLoose])
                       ->orWhere('reference', 'like', "%{$needleExact}%");
                });
            })
            ->when(ctype_digit($needleLoose), function ($q) use ($needleLoose) {
                // If the user pasted a numeric VEVS booking_id like 000420
                // and you’ve ever stored it inside the reference or as suffix/prefix,
                // this loose match helps.
                $q->orWhere('reference', 'like', "%{$needleLoose}%");
            })
            ->with('customer')
            ->first();

        if (!$booking) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reference' => 'We couldn’t find a booking with that reference.',
            ]);
        }

        // Emails we’ll accept for this booking
        $bookingEmails = array_filter([
            strtolower((string) data_get($booking, 'customer.email')),
            strtolower((string) data_get($booking, 'meta.customer.email')),
            strtolower((string) data_get($booking, 'meta.email')),
        ]);

        $emailMatches = in_array($email, $bookingEmails, true);
        $canClaim     = !$booking->customer_id; // allow attach if not yet linked

        if (!$emailMatches && !$canClaim) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'This email doesn’t match the booking’s email.',
            ]);
        }

        /** @var \App\Models\Customer $customer */
        $customer = \App\Models\Customer::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => (string) data_get($booking, 'meta.customer.name', ''),
                'full_name'  => (string) data_get($booking, 'meta.customer.name', ''),
            ]
        );

        if (!$booking->customer_id) {
            $booking->customer_id = $customer->id;
            $booking->save();
        }

        \Illuminate\Support\Facades\Auth::guard('customer')->login($customer, true);

        return \Illuminate\Support\Facades\Redirect::route('customer.bookings');
    }

    /** Log out. */
    public function logout()
    {
        Auth::guard('customer')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('customer.login');
    }

    /** “My bookings” page. */
    public function bookings()
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->latest('start_at')
            ->get();

        return view('customer.bookings.index', [
            'customer'  => $customer,
            'bookings'  => $bookings,
        ]);
    }
}
