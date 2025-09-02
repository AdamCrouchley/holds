{{-- resources/views/bookings/show.blade.php --}}
<x-app-layout container="max-w-none w-full">
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Booking #{{ $booking->id }} @if($booking->reference) • {{ $booking->reference }} @endif
                </h1>
                <p class="text-sm text-gray-600">
                    {{ $booking->start_at?->timezone(config('app.timezone'))?->format('D, d M Y H:i') ?? '—' }}
                    → {{ $booking->end_at?->timezone(config('app.timezone'))?->format('D, d M Y H:i') ?? '—' }}
                    @if($booking->status) • Status: <span class="font-medium">{{ Str::headline($booking->status) }}</span>@endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                @if($booking->status)
                    <span class="px-2 py-1 text-xs rounded
                        @class([
                            'bg-amber-50 text-amber-700 ring-1 ring-amber-200' => $booking->status === 'pending',
                            'bg-blue-50 text-blue-700 ring-1 ring-blue-200' => $booking->status === 'confirmed',
                            'bg-green-50 text-green-700 ring-1 ring-green-200' => in_array($booking->status, ['active','completed','complete']),
                            'bg-rose-50 text-rose-700 ring-1 ring-rose-200' => in_array($booking->status, ['cancelled','canceled']),
                            'bg-gray-50 text-gray-700 ring-1 ring-gray-200' => !in_array($booking->status, ['pending','confirmed','active','completed','complete','cancelled','canceled']),
                        ])
                    ">
                        {{ Str::headline($booking->status) }}
                    </span>
                @endif
                @if(route('bookings.edit', $booking) ?? false)
                    <a href="{{ route('bookings.edit', $booking) }}"
                       class="inline-flex items-center rounded-md bg-gray-900 text-white text-sm px-3 py-1.5 hover:bg-gray-800">
                        Edit
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    {{-- Body --}}
    <div class="px-4 py-6 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left column: booking summary --}}
            <div class="space-y-6 lg:col-span-2">
                {{-- Booking summary card (example fields – adjust to your schema) --}}
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h2 class="text-sm font-semibold text-gray-900">Booking Summary</h2>
                    </div>
                    <div class="px-5 py-4 text-sm text-gray-800">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500">Vehicle</dt>
                                <dd class="mt-0.5">
                                    {{ $booking->vehicle?->display_name ?? $booking->vehicle?->registration ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Pickup</dt>
                                <dd class="mt-0.5">
                                    {{ $booking->start_at?->format('D, d M Y H:i') ?? '—' }}
                                    @if($booking->pickup_location) • {{ $booking->pickup_location }} @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Dropoff</dt>
                                <dd class="mt-0.5">
                                    {{ $booking->end_at?->format('D, d M Y H:i') ?? '—' }}
                                    @if($booking->dropoff_location) • {{ $booking->dropoff_location }} @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Total</dt>
                                <dd class="mt-0.5">
                                    @php
                                        $currency = $booking->currency ?? 'NZD';
                                        $total = isset($booking->total_cents) ? number_format($booking->total_cents / 100, 2) : null;
                                    @endphp
                                    {{ $total ? ($currency . ' ' . $total) : '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                {{-- Any other booking-related panels can go here --}}
            </div>

            {{-- Right column: Customer details --}}
            @php
                $customer = $booking->customer ?? null;

                // Prefer customer model fields; fall back to booking snapshot fields if you store them there.
                $email   = $customer?->email     ?? $booking->customer_email     ?? null;
                $phone   = $customer?->phone     ?? $booking->customer_phone     ?? null;

                // Postal address (Customer model typical fields)
                $addrL1  = $customer?->address_line1 ?? $booking->address_line1 ?? null;
                $addrL2  = $customer?->address_line2 ?? $booking->address_line2 ?? null;
                $addrCity= $customer?->address_city  ?? $booking->address_city  ?? null;
                $addrReg = $customer?->address_region?? $booking->address_region?? null;
                $addrPC  = $customer?->address_postcode ?? $booking->address_postcode ?? null;
                $addrCtry= $customer?->address_country ?? $booking->address_country ?? null;

                $addressParts = array_filter([$addrL1, $addrL2, $addrCity, $addrReg, $addrPC, $addrCtry]);
                $address = $addressParts ? implode(', ', $addressParts) : null;

                // Driver’s licence – try Customer first, then Booking fallbacks
                $dlNumber   = $customer->drivers_license_number   ?? $booking->drivers_license_number   ?? null;
                $dlCountry  = $customer->drivers_license_country  ?? $booking->drivers_license_country  ?? null;
                $dlExpiry   = $customer->drivers_license_expiry   ?? $booking->drivers_license_expiry   ?? null;
                $dlClass    = $customer->drivers_license_class    ?? $booking->drivers_license_class    ?? null;
                $dob        = $customer->date_of_birth            ?? $booking->date_of_birth            ?? null;

                // Names
                $customerName = $customer?->name
                    ?? trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''))
                    ?: 'Customer';

                // Convenience formatting
                $fmtExpiry = $dlExpiry ? \Illuminate\Support\Carbon::parse($dlExpiry)->format('d M Y') : null;
                $fmtDob    = $dob ? \Illuminate\Support\Carbon::parse($dob)->format('d M Y') : null;
            @endphp

            <div class="space-y-6">
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-5 py-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Customer</h2>
                        @if($customer && (route('customers.show', $customer) ?? false))
                            <a href="{{ route('customers.show', $customer) }}"
                               class="text-sm text-indigo-600 hover:text-indigo-700">View customer</a>
                        @endif
                    </div>
                    <div class="px-5 py-4 text-sm text-gray-800">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-3">
                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Name</dt>
                                <dd class="mt-0.5 sm:col-span-2">{{ $customerName }}</dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Email</dt>
                                <dd class="mt-0.5 sm:col-span-2">
                                    @if($email)
                                        <a href="mailto:{{ $email }}" class="text-indigo-700 hover:underline">{{ $email }}</a>
                                    @else
                                        <span class="text-gray-400">N/A</span>
                                    @endif
                                </dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Phone</dt>
                                <dd class="mt-0.5 sm:col-span-2">
                                    @if($phone)
                                        <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}" class="text-indigo-700 hover:underline">{{ $phone }}</a>
                                    @else
                                        <span class="text-gray-400">N/A</span>
                                    @endif
                                </dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Address</dt>
                                <dd class="mt-0.5 sm:col-span-2">
                                    @if($address)
                                        {{ $address }}
                                    @else
                                        <span class="text-gray-400">N/A</span>
                                    @endif
                                </dd>
                            </div>

                            <div class="border-t border-gray-100 my-2"></div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Driver’s licence #</dt>
                                <dd class="mt-0.5 sm:col-span-2">
                                    {{ $dlNumber ?? '—' }}
                                    @if($dlClass)
                                        <span class="text-gray-500"> (Class {{ $dlClass }})</span>
                                    @endif
                                </dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Issuing country</dt>
                                <dd class="mt-0.5 sm:col-span-2">{{ $dlCountry ?? '—' }}</dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Licence expiry</dt>
                                <dd class="mt-0.5 sm:col-span-2">{{ $fmtExpiry ?? '—' }}</dd>
                            </div>

                            <div class="sm:grid sm:grid-cols-3 sm:gap-6">
                                <dt class="text-gray-500">Date of birth</dt>
                                <dd class="mt-0.5 sm:col-span-2">{{ $fmtDob ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center gap-3">
                            @if($email)
                                <a href="mailto:{{ $email }}"
                                   class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-white text-sm hover:bg-indigo-700">
                                    Email customer
                                </a>
                            @endif
                            @if($phone)
                                <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}"
                                   class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-white text-sm hover:bg-gray-800">
                                    Call customer
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Debug helper (remove if not needed) --}}
        @if(app()->isLocal())
            <div class="mt-6 text-xs text-gray-500">
                {{-- DEBUG: Ensure the view gets a Booking with ->customer relation eager loaded --}}
                Customer ID: {{ $booking->customer_id ?? 'NULL' }}
            </div>
        @endif
    </div>
</x-app-layout>
