{{-- resources/views/portal/home.blade.php --}}
<x-app-layout title="Customer Portal">
    <x-slot name="header">
        <h2 class="text-xl font-semibold">Customer Portal</h2>
    </x-slot>

    @php
        // Safe defaults
        $bookings = collect($bookings ?? []);
        $customer = $customer ?? null;

        // Display helpers
        $tz = $customer->portal_timezone ?? config('app.timezone');

        $money = function (?int $cents, ?string $cur = 'NZD') {
            $cents = (int) ($cents ?? 0);
            return ($cur ?: 'NZD') . ' ' . number_format($cents / 100, 2);
        };

        $paidSum = function ($b) {
            return (int) ($b->payments?->whereIn('status', ['succeeded', 'paid', 'captured', 'completed'])->sum('amount') ?? 0);
        };

        $carLabel = function ($b) {
            // Try a few common fields on a related vehicle, then fall back to a string column
            $v = $b->vehicle ?? null; // relation or string
            return $b->car_label
                ?? ($v->name ?? $v->label ?? $v->title ?? null)
                ?? (is_string($v) ? $v : null)
                ?? '—';
        };

        // Next upcoming booking
        $next = $bookings
            ->filter(fn ($b) => $b->start_at && $b->start_at->isFuture())
            ->sortBy('start_at')
            ->first();
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6">

            {{-- Welcome + Logout --}}
            <div class="flex items-center justify-between bg-white p-4 rounded shadow">
                <div>
                    <h1 class="text-lg font-semibold">
                        Welcome, {{ $customer?->first_name ?: ($customer?->full_name ?? $customer?->email ?? 'Guest') }}
                    </h1>
                    @if($customer?->portal_timezone)
                        <p class="text-xs text-gray-500">Times shown in {{ $customer->portal_timezone }}</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="rounded bg-gray-200 px-3 py-2 hover:bg-gray-300">Log out</button>
                </form>
            </div>

            {{-- Alerts --}}
            @if(session('claim_ok'))
                <div class="rounded border border-green-200 bg-green-50 p-3 text-green-700">
                    {{ session('claim_ok') }}
                </div>
            @endif
            @if(session('claim_error'))
                <div class="rounded border border-red-200 bg-red-50 p-3 text-red-700">
                    {{ session('claim_error') }}
                </div>
            @endif

            {{-- Next / Upcoming booking --}}
            @if($next)
                @php
                    $paid    = $paidSum($next);
                    $total   = (int) ($next->total_amount ?? 0);
                    $balance = max(0, $total - $paid);
                @endphp

                <div class="bg-white rounded shadow p-4">
                    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">Your next booking</h2>
                            <p class="text-sm text-gray-600 mt-0.5">
                                Ref <span class="font-mono">{{ $next->reference }}</span>
                                • {{ $carLabel($next) }}
                            </p>
                            <p class="mt-1 text-sm">
                                <span class="font-medium">Start:</span>
                                {{ optional($next->start_at)?->timezone($tz)->format('D, d M Y · h:ia') ?? '—' }}
                                &nbsp;&nbsp;—&nbsp;&nbsp;
                                <span class="font-medium">End:</span>
                                {{ optional($next->end_at)?->timezone($tz)->format('D, d M Y · h:ia') ?? '—' }}
                            </p>
                        </div>

                        <div class="grid grid-cols-3 gap-3 text-sm">
                            <div class="rounded border p-3">
                                <div class="text-gray-500">Total</div>
                                <div class="font-semibold">
                                    {{ $money($total, $next->currency ?? 'NZD') }}
                                </div>
                            </div>
                            <div class="rounded border p-3">
                                <div class="text-gray-500">Paid</div>
                                <div class="font-semibold">
                                    {{ $money($paid, $next->currency ?? 'NZD') }}
                                </div>
                            </div>
                            <div class="rounded border p-3">
                                <div class="text-gray-500">Balance</div>
                                <div class="font-semibold {{ $balance > 0 ? 'text-orange-600' : 'text-emerald-600' }}">
                                    {{ $money($balance, $next->currency ?? 'NZD') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('portal.pay', ['booking' => $next->id]) }}"
                           class="inline-flex items-center rounded bg-sky-600 px-4 py-2 text-white hover:bg-sky-700">
                            Pay / View booking
                        </a>
                    </div>
                </div>
            @endif

            {{-- All bookings --}}
            <div class="bg-white rounded shadow p-4">
                <h2 class="text-lg font-semibold mb-3">My Bookings</h2>

                @if($bookings->isEmpty())
                    <p class="text-gray-500">No bookings found for your account.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b bg-gray-50 text-left">
                                    <th class="p-2">Reference</th>
                                    <th class="p-2">Car</th>
                                    <th class="p-2">Start</th>
                                    <th class="p-2">End</th>
                                    <th class="p-2">Total</th>
                                    <th class="p-2">Paid</th>
                                    <th class="p-2">Balance</th>
                                    <th class="p-2"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($bookings as $b)
                                    @php
                                        $paid    = $paidSum($b);
                                        $total   = (int) ($b->total_amount ?? 0);
                                        $balance = max(0, $total - $paid);
                                    @endphp
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="p-2 font-mono">{{ $b->reference }}</td>
                                        <td class="p-2">{{ $carLabel($b) }}</td>
                                        <td class="p-2">{{ optional($b->start_at)?->timezone($tz)->format('D, d M Y · h:ia') ?? '—' }}</td>
                                        <td class="p-2">{{ optional($b->end_at)?->timezone($tz)->format('D, d M Y · h:ia') ?? '—' }}</td>
                                        <td class="p-2 whitespace-nowrap">{{ $money($total, $b->currency ?? 'NZD') }}</td>
                                        <td class="p-2 whitespace-nowrap">{{ $money($paid, $b->currency ?? 'NZD') }}</td>
                                        <td class="p-2 whitespace-nowrap {{ $balance > 0 ? 'text-orange-600' : 'text-emerald-600' }}">
                                            {{ $money($balance, $b->currency ?? 'NZD') }}
                                        </td>
                                        <td class="p-2">
                                            <a class="inline-flex items-center rounded bg-sky-500 text-white px-3 py-1 hover:bg-sky-600"
                                               href="{{ route('portal.pay', ['booking' => $b->id]) }}">
                                                View / Pay
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Claim a booking --}}
            <div class="bg-white rounded shadow p-4">
                <h3 class="text-lg font-semibold mb-2">Claim a booking</h3>
                <form class="flex flex-wrap items-center gap-2" method="POST" action="{{ route('portal.claim') }}">
                    @csrf
                    <label class="text-sm font-medium" for="reference">Reference</label>
                    <input id="reference" name="reference" placeholder="e.g. QW1756187813" required
                           class="rounded border border-gray-300 px-2 py-1 text-sm">
                    <button class="rounded bg-orange-500 px-3 py-1 text-white hover:bg-orange-600">
                        Claim
                    </button>
                </form>
                <p class="text-gray-500 text-sm mt-2">
                    Use this if an older booking isn’t attached to your account.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
