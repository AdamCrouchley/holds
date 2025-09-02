<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking;

class PortalDashboardController extends Controller
{
    public function index()
    {
        $customer = Auth::guard('customer')->user();
        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('start_at')
            ->get();

        return view('portal.home', compact('customer','bookings'));
    }

    // Claim a booking by reference and attach it to the logged-in customer
    public function claim(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required','string','max:191'],
        ]);

        $customer = Auth::guard('customer')->user();

        $booking = Booking::where('reference', $data['reference'])->first();
        if (! $booking) {
            return back()->with('claim_error', 'Sorry, that reference was not found.');
        }

        $booking->customer_id = $customer->id;
        $booking->save();

        return back()->with('claim_ok', 'Booking attached to your account.');
    }
}
