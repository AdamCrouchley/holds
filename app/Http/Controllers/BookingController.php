<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    /**
     * List bookings with lightweight filters.
     * GET /bookings
     */
    public function index(Request $request)
    {
        $q        = trim((string) $request->query('q', ''));
        $status   = (string) $request->query('status', '');
        $fromDate = (string) $request->query('from', '');
        $toDate   = (string) $request->query('to', '');

        $query = Booking::query()->with('customer');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                if (Schema::hasColumn('bookings', 'reference')) {
                    $sub->orWhere('reference', 'ILIKE', "%{$q}%");
                    $sub->orWhere('reference', 'LIKE', "%{$q}%"); // for sqlite/mysql
                }
                if (Schema::hasColumn('bookings', 'vehicle')) {
                    $sub->orWhere('vehicle', 'LIKE', "%{$q}%");
                }
                if (Schema::hasColumn('bookings', 'customer_email')) {
                    $sub->orWhere('customer_email', 'ILIKE', "%{$q}%")
                        ->orWhere('customer_email', 'LIKE', "%{$q}%");
                }
                $sub->orWhereHas('customer', function ($cq) use ($q) {
                    $cq->where('email', 'LIKE', "%{$q}%")
                       ->orWhere('first_name', 'LIKE', "%{$q}%")
                       ->orWhere('last_name', 'LIKE', "%{$q}%");
                });
            });
        }

        if ($status !== '' && Schema::hasColumn('bookings', 'status')) {
            $query->where('status', $status);
        }

        // Date filtering: uses start_at when present, else created_at
        $dateCol = Schema::hasColumn('bookings', 'start_at') ? 'start_at' : 'created_at';
        if ($fromDate !== '') {
            $query->where($dateCol, '>=', Carbon::parse($fromDate)->startOfDay());
        }
        if ($toDate !== '') {
            $query->where($dateCol, '<=', Carbon::parse($toDate)->endOfDay());
        }

        $bookings = $query
            ->orderByDesc($dateCol)
            ->paginate(25)
            ->withQueryString();

        // Provide counts for quick status tabs if status column exists
        $statusCounts = [];
        if (Schema::hasColumn('bookings', 'status')) {
            $statusCounts = Booking::selectRaw('status, count(*) as c')
                ->groupBy('status')->pluck('c', 'status')->toArray();
        }

        return view('bookings.index', compact('bookings', 'q', 'status', 'fromDate', 'toDate', 'statusCounts'));
    }

    /**
     * Show create form.
     * GET /bookings/create
     */
    public function create()
    {
        $currencies = ['NZD','AUD','USD','EUR','GBP'];
        return view('bookings.create', compact('currencies'));
    }

    /**
     * Store a new booking.
     * POST /bookings
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reference'       => ['nullable','string','max:64'],
            'customer_id'     => ['nullable','integer','exists:customers,id'],
            'customer_email'  => ['nullable','email'],
            'status'          => ['nullable','string','max:32'],
            'currency'        => ['nullable','string','size:3'],
            'start_at'        => ['nullable','date'],
            'end_at'          => ['nullable','date','after_or_equal:start_at'],
            'total_amount'    => ['nullable','integer','min:0'],
            'deposit_amount'  => ['nullable','integer','min:0'],
            'hold_amount'     => ['nullable','integer','min:0'],
            'vehicle'         => ['nullable','string','max:120'],
            'brand'           => ['nullable','string','max:60'],
        ]);

        $booking = new Booking();

        // Assign columns defensively
        foreach ($data as $k => $v) {
            if (Schema::hasColumn('bookings', $k)) {
                $booking->{$k} = $v;
            }
        }

        // Fallbacks
        if (empty($booking->currency) && Schema::hasColumn('bookings', 'currency')) {
            $booking->currency = 'NZD';
        }
        if (Schema::hasColumn('bookings', 'status') && empty($booking->status)) {
            $booking->status = 'pending';
        }

        // Ensure portal_token exists even if model boot() hasn't added it yet
        if (Schema::hasColumn('bookings', 'portal_token') && empty($booking->portal_token)) {
            $booking->portal_token = Str::random(40);
        }

        // If reference is required/unique in your schema, auto-generate if blank
        if (Schema::hasColumn('bookings', 'reference') && empty($booking->reference)) {
            $booking->reference = $this->generateReference();
        }

        $booking->save();

        return redirect()->route('bookings.show', $booking)->with('status', 'Booking created.');
    }

    /**
     * Show one booking.
     * GET /bookings/{booking}
     */
    public function show(Booking $booking)
    {
        $booking->loadMissing(['customer', 'payments']);
        $paid    = $this->sumPaid($booking);
        $total   = (int) ($booking->total_amount ?? 0);
        $balance = max(0, $total - $paid);

        // Build a portal pay link (token flow) if available
        $portalUrl = null;
        if (Schema::hasColumn('bookings', 'portal_token') && !empty($booking->portal_token)) {
            $portalUrl = route('portal.pay.token', $booking->portal_token);
        }

        return view('bookings.show', compact('booking', 'paid', 'total', 'balance', 'portalUrl'));
    }

    /**
     * Show edit form.
     * GET /bookings/{booking}/edit
     */
    public function edit(Booking $booking)
    {
        $currencies = ['NZD','AUD','USD','EUR','GBP'];
        return view('bookings.edit', compact('booking', 'currencies'));
    }

    /**
     * Update booking.
     * PUT/PATCH /bookings/{booking}
     */
    public function update(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'reference'       => ['nullable','string','max:64'],
            'customer_id'     => ['nullable','integer','exists:customers,id'],
            'customer_email'  => ['nullable','email'],
            'status'          => ['nullable','string','max:32'],
            'currency'        => ['nullable','string','size:3'],
            'start_at'        => ['nullable','date'],
            'end_at'          => ['nullable','date','after_or_equal:start_at'],
            'total_amount'    => ['nullable','integer','min:0'],
            'deposit_amount'  => ['nullable','integer','min:0'],
            'hold_amount'     => ['nullable','integer','min:0'],
            'vehicle'         => ['nullable','string','max:120'],
            'brand'           => ['nullable','string','max:60'],
        ]);

        foreach ($data as $k => $v) {
            if (Schema::hasColumn('bookings', $k)) {
                $booking->{$k} = $v;
            }
        }

        // Keep token alive (or generate if missing)
        if (Schema::hasColumn('bookings', 'portal_token') && empty($booking->portal_token)) {
            $booking->portal_token = Str::random(40);
        }

        $booking->save();

        return redirect()->route('bookings.show', $booking)->with('status', 'Booking updated.');
    }

    /**
     * Delete booking (soft or hard depending on model).
     * DELETE /bookings/{booking}
     */
    public function destroy(Booking $booking)
    {
        $booking->delete();
        return redirect()->route('bookings.index')->with('status', 'Booking removed.');
    }

    /**
     * POST /bookings/{booking}/regenerate-token
     * Regenerate a portal token & return fresh link.
     */
    public function regeneratePortalToken(Booking $booking)
    {
        if (!Schema::hasColumn('bookings', 'portal_token')) {
            return back()->with('claim_error', 'No portal token column on bookings table.');
        }

        $booking->portal_token = Str::random(40);
        $booking->save();

        $url = route('portal.pay.token', $booking->portal_token);

        return back()->with('status', 'Portal token regenerated.')
                     ->with('portal_url', $url);
    }

    /**
     * POST /bookings/{booking}/send-portal-link
     * (Log-only demo; swap to your mailer/notification.)
     */
    public function sendPortalLink(Booking $booking)
    {
        if (!Schema::hasColumn('bookings', 'portal_token') || empty($booking->portal_token)) {
            $booking->portal_token = Str::random(40);
            $booking->save();
        }

        $url = route('portal.pay.token', $booking->portal_token);

        Log::info('[bookings/send-portal-link]', [
            'booking_id' => $booking->getKey(),
            'reference'  => $booking->reference ?? null,
            'to'         => $booking->customer?->email ?? $booking->customer_email ?? null,
            'url'        => $url,
        ]);

        // TODO: dispatch a mailable/notification to customer
        return back()->with('status', 'Portal link queued to send.')->with('portal_url', $url);
    }

    /**
     * POST /bookings/{booking}/attach-customer
     * Attach by email (creates Customer if needed).
     */
    public function attachCustomerByEmail(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'email'      => ['required','email'],
            'first_name' => ['nullable','string','max:60'],
            'last_name'  => ['nullable','string','max:60'],
        ]);

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($data['email'])],
            ['first_name' => $data['first_name'] ?? '', 'last_name' => $data['last_name'] ?? '']
        );

        if (Schema::hasColumn('bookings', 'customer_id')) {
            $booking->customer()->associate($customer);
        }
        if (Schema::hasColumn('bookings', 'customer_email')) {
            $booking->customer_email = $customer->email;
        }
        $booking->save();

        return back()->with('status', 'Customer attached to booking.');
    }

    /**
     * POST /bookings/{booking}/payments
     * Add a manual payment row (admin use).
     */
    public function addPayment(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'amount'   => ['required','integer','min:1'],   // cents
            'currency' => ['nullable','string','size:3'],
            'status'   => ['nullable','string','max:32'],   // e.g. succeeded/paid
            'purpose'  => ['nullable','string','max:64'],   // deposit/balance/hold/etc
            'notes'    => ['nullable','string','max:500'],
        ]);

        $payment = new Payment();

        if (Schema::hasColumn('payments', 'booking_id'))  $payment->booking_id  = $booking->getKey();
        if (Schema::hasColumn('payments', 'customer_id')) $payment->customer_id = $booking->customer?->getKey();

        $payment->amount = (int) $data['amount'];

        if (Schema::hasColumn('payments', 'currency')) $payment->currency = strtoupper($data['currency'] ?? ($booking->currency ?? 'NZD'));
        if (Schema::hasColumn('payments', 'status'))   $payment->status   = $data['status'] ?? 'succeeded';

        // map to purpose/type depending on your schema
        if (Schema::hasColumn('payments', 'purpose')) {
            $payment->purpose = $data['purpose'] ?? 'custom';
        } elseif (Schema::hasColumn('payments', 'type')) {
            $payment->type = $this->mapPurposeToLegacyType($data['purpose'] ?? 'custom');
        }

        if (Schema::hasColumn('payments', 'notes') && !empty($data['notes'])) {
            $payment->notes = $data['notes'];
        }

        $payment->save();

        return back()->with('status', 'Payment added.');
    }

    /**
     * GET /bookings/{booking}/amounts
     * Lightweight JSON for UI widgets.
     */
    public function amounts(Booking $booking)
    {
        $paid    = $this->sumPaid($booking->loadMissing('payments'));
        $total   = (int) ($booking->total_amount ?? 0);
        $balance = max(0, $total - $paid);
        $hold    = (int) ($booking->hold_amount ?? 0);

        return response()->json(compact('total','paid','balance','hold'));
    }


    /* =======================================================================
     | Helpers
     * ======================================================================= */

    private function sumPaid(Booking $booking): int
    {
        $statuses = ['succeeded', 'paid', 'captured', 'completed'];
        return (int) ($booking->payments?->whereIn('status', $statuses)->sum('amount') ?? 0);
    }

    private function generateReference(): string
    {
        // Example: DD + yymmdd + 4 random
        do {
            $ref = sprintf('%s%s%s', 'DD', now()->format('ymd'), Str::upper(Str::random(4)));
        } while (Schema::hasColumn('bookings', 'reference') &&
                 Booking::where('reference', $ref)->exists());

        return $ref;
    }

    private function mapPurposeToLegacyType(string $purpose): string
    {
        $map = [
            'booking_deposit'  => 'deposit',
            'deposit'          => 'deposit',
            'booking_balance'  => 'balance',
            'balance'          => 'balance',
            'bond_hold'        => 'hold',
            'hold'             => 'hold',
            'bond_void'        => 'refund',
            'refund'           => 'refund',
            'bond_capture'     => 'post_hire_charge',
            'post_hire_charge' => 'post_hire_charge',
            'balance_plus_bond'=> 'balance',
            'custom'           => 'balance',
        ];
        return $map[$purpose] ?? 'balance';
    }
}
