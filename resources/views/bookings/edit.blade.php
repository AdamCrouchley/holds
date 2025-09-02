{{-- resources/views/bookings/edit.blade.php --}}
@extends('layouts.app')

@section('title', 'Edit Booking — ' . ($booking->reference ?? 'Booking'))

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Edit Booking
                @if(!empty($booking->reference))
                    <span class="text-gray-400">•</span>
                    <span class="text-gray-600">{{ $booking->reference }}</span>
                @endif
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Update core details, dates, and payments. Amount fields are in NZD (dollars).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('bookings.index') }}"
               class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            {{-- Optional: delete button if you have a destroy route --}}
            {{-- <form method="POST" action="{{ route('bookings.destroy', $booking) }}">
                @csrf @method('DELETE')
                <button type="submit"
                        class="inline-flex items-center rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                        onclick="return confirm('Delete this booking?')">
                    Delete
                </button>
            </form> --}}
        </div>
    </div>

    {{-- Flash message --}}
    @if(session('status'))
        <div class="mb-6 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 border border-green-200">
            {{ session('status') }}
        </div>
    @endif

    {{-- Form --}}
    <form method="POST" action="{{ route('bookings.update', $booking) }}" id="booking-edit-form">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Customer summary (read-only) --}}
                <section class="rounded-2xl border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-4">Customer</h2>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <div class="min-w-[200px]">
                            <div class="text-sm text-gray-500">Name</div>
                            <div class="text-sm font-medium text-gray-900">
                                {{ $booking->customer->name ?? trim(($booking->customer->first_name ?? '') . ' ' . ($booking->customer->last_name ?? '')) ?: '—' }}
                            </div>
                        </div>
                        <div class="min-w-[200px]">
                            <div class="text-sm text-gray-500">Email</div>
                            <div class="text-sm font-medium text-gray-900">
                                {{ $booking->customer->email ?? '—' }}
                            </div>
                        </div>
                        <div class="min-w-[200px]">
                            <div class="text-sm text-gray-500">Phone</div>
                            <div class="text-sm font-medium text-gray-900">
                                {{ $booking->customer->phone ?? '—' }}
                            </div>
                        </div>
                    </div>
                    {{-- keep customer_id if you allow reassigning elsewhere --}}
                    <input type="hidden" name="customer_id" value="{{ $booking->customer_id }}">
                </section>

                {{-- Booking details --}}
                <section class="rounded-2xl border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-4">Booking</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Reference --}}
                        <div>
                            <label for="reference" class="block text-sm font-medium text-gray-700">Reference</label>
                            <input type="text" id="reference" name="reference"
                                   value="{{ old('reference', $booking->reference) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('reference') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Status --}}
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            @php
                                $statusOptions = ['pending' => 'Pending', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
                            @endphp
                            <select id="status" name="status"
                                    class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                @foreach($statusOptions as $val => $label)
                                    <option value="{{ $val }}" @selected(old('status', $booking->status) === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Currency --}}
                        <div>
                            <label for="currency" class="block text-sm font-medium text-gray-700">Currency</label>
                            <input type="text" id="currency" name="currency"
                                   value="{{ old('currency', $booking->currency ?? 'NZD') }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="NZD">
                            @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Vehicle (optional) --}}
                        <div>
                            <label for="vehicle" class="block text-sm font-medium text-gray-700">Vehicle</label>
                            <input type="text" id="vehicle" name="vehicle"
                                   value="{{ old('vehicle', $booking->vehicle ?? '') }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="e.g., Dream Drives — Model">
                            @error('vehicle') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Start at --}}
                        <div>
                            <label for="start_at" class="block text-sm font-medium text-gray-700">Start</label>
                            <input type="datetime-local" id="start_at" name="start_at"
                                   value="{{ old('start_at', optional($booking->start_at)->format('Y-m-d\TH:i')) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('start_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- End at --}}
                        <div>
                            <label for="end_at" class="block text-sm font-medium text-gray-700">End</label>
                            <input type="datetime-local" id="end_at" name="end_at"
                                   value="{{ old('end_at', optional($booking->end_at)->format('Y-m-d\TH:i')) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('end_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </section>

                {{-- Money --}}
                <section class="rounded-2xl border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-4">Payment & Amounts (NZD)</h2>

                    @php
                        $toDollars = fn($cents) => number_format((int)($cents ?? 0) / 100, 2, '.', '');
                    @endphp

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {{-- Total --}}
                        <div>
                            <label for="total_amount" class="block text-sm font-medium text-gray-700">Total</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                   id="total_amount" name="total_amount"
                                   value="{{ old('total_amount', $toDollars($booking->total_amount)) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 amount-input">
                            @error('total_amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Deposit --}}
                        <div>
                            <label for="deposit_amount" class="block text-sm font-medium text-gray-700">Deposit</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                   id="deposit_amount" name="deposit_amount"
                                   value="{{ old('deposit_amount', $toDollars($booking->deposit_amount)) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 amount-input">
                            @error('deposit_amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Bond / Hold --}}
                        <div>
                            <label for="hold_amount" class="block text-sm font-medium text-gray-700">Bond Hold</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                   id="hold_amount" name="hold_amount"
                                   value="{{ old('hold_amount', $toDollars($booking->hold_amount)) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 amount-input">
                            @error('hold_amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Already Paid (new) --}}
                        <div>
                            <label for="paid_amount" class="block text-sm font-medium text-gray-700">Already Paid</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                   id="paid_amount" name="paid_amount"
                                   value="{{ old('paid_amount', method_exists($booking,'getPaidAmountDollarsAttribute') ? $booking->paid_amount_dollars : $toDollars($booking->paid_amount)) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 amount-input">
                            @error('paid_amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Optional note about cents handling --}}
                    <p class="mt-3 text-xs text-gray-500">
                        These fields accept dollars (e.g. “409.00”). They’ll be stored as cents in the database.
                    </p>
                </section>

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('bookings.index') }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </a>
                    <button type="submit"
                            class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200">
                        Save changes
                    </button>
                </div>
            </div>

            {{-- Sidebar summary --}}
            <aside class="space-y-6">
                <section class="rounded-2xl border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Summary</h3>
                    @php
                        $totalC   = (int) ($booking->total_amount ?? 0);
                        $paidC    = (int) ($booking->paid_amount ?? 0);
                        $remainC  = max($totalC - $paidC, 0);
                    @endphp
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Total</dt>
                            <dd class="font-medium" id="sum-total">${{ number_format($totalC/100, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Already Paid</dt>
                            <dd class="font-medium" id="sum-paid">${{ number_format($paidC/100, 2) }}</dd>
                        </div>
                        <div class="border-t border-gray-100 pt-2 flex justify-between">
                            <dt class="text-gray-900">Remaining Due</dt>
                            <dd class="font-semibold text-gray-900" id="sum-remaining">${{ number_format($remainC/100, 2) }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Portal link (if available) --}}
                @if(!empty($booking->portal_token ?? null))
                    <section class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
                        <h3 class="text-sm font-semibold text-indigo-900 mb-2">Guest Portal</h3>
                        <p class="text-sm text-indigo-900/80 mb-3">
                            Share this with the customer to pay balance and bond.
                        </p>
                        <div class="text-xs break-all rounded-lg bg-white/60 border border-indigo-200 px-3 py-2 text-indigo-900">
                            {{ url('/p/b/' . $booking->portal_token) }}
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </form>
</div>

{{-- Lightweight live summary updater (no dependencies) --}}
<script>
(function(){
    const $ = (sel) => document.querySelector(sel);
    const fmt = (n) => isFinite(n) ? (n/1).toFixed(2) : '0.00';
    function val(id){
        const el = document.getElementById(id);
        if(!el) return 0;
        const v = parseFloat((el.value || '').replace(/[^0-9.\-]/g,''));
        return isNaN(v) ? 0 : v;
    }
    function update(){
        const total = val('total_amount');
        const paid  = val('paid_amount');
        $('#sum-total') && ($('#sum-total').textContent = '$' + fmt(total));
        $('#sum-paid') && ($('#sum-paid').textContent   = '$' + fmt(paid));
        const rem = Math.max(total - paid, 0);
        $('#sum-remaining') && ($('#sum-remaining').textContent = '$' + fmt(rem));
    }
    document.querySelectorAll('.amount-input').forEach(el => {
        ['input','change','blur'].forEach(ev => el.addEventListener(ev, update));
    });
    update();
})();
</script>
@endsection
