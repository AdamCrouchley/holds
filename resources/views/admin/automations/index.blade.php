@extends('layouts.app')

@section('title','Automations')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-semibold mb-6">Automations</h1>

    @if(session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-green-700 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.automations.save') }}"
          class="rounded-2xl border border-gray-200 bg-white p-6 space-y-6">
        @csrf

        <div class="flex items-center gap-3">
            <input type="checkbox" id="active" name="active" value="1"
                   @checked(old('active', $settings->active)) class="rounded">
            <label for="active" class="text-sm font-medium text-gray-800">
                Enable automations
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Balance request — days before start</label>
                <input type="number" name="send_balance_days_before" min="0" max="60"
                       value="{{ old('send_balance_days_before', $settings->send_balance_days_before) }}"
                       class="mt-1 w-full rounded-lg border-gray-300">
                @error('send_balance_days_before') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Bond request — days before start</label>
                <input type="number" name="send_bond_days_before" min="0" max="60"
                       value="{{ old('send_bond_days_before', $settings->send_bond_days_before) }}"
                       class="mt-1 w-full rounded-lg border-gray-300">
                @error('send_bond_days_before') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Send at (local time)</label>
                <input type="time" name="send_at_local"
                       value="{{ old('send_at_local', \Illuminate\Support\Str::of($settings->send_at_local)->limit(5, '') ) }}"
                       class="mt-1 w-full rounded-lg border-gray-300">
                @error('send_at_local') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Timezone</label>
                <input type="text" name="timezone" placeholder="Pacific/Auckland"
                       value="{{ old('timezone', $settings->timezone) }}"
                       class="mt-1 w-full rounded-lg border-gray-300">
                @error('timezone') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end">
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700">Save</button>
        </div>
    </form>

    <p class="text-xs text-gray-500 mt-4">
        The scheduler checks every 15 minutes and sends within the set local hour window.
    </p>
</div>
@endsection
