@php($title = 'Login to your portal')

@extends('layouts.portal')

@section('content')
  <div class="rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 overflow-hidden">
    <div class="px-6 py-6 md:px-8 md:py-8 border-b border-slate-100">
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
      <p class="mt-1 text-sm text-slate-500">
        Use your email to get a one-time code and access your booking portal.
      </p>
    </div>

    <div class="px-6 py-6 md:px-8 md:py-8 space-y-6">
      @if (session('status'))
        <x-portal.alert type="success">{{ session('status') }}</x-portal.alert>
      @endif
      @if ($errors->any())
        <x-portal.alert type="error">
          <ul class="list-disc list-inside">
            @foreach ($errors->all() as $err)
              <li>{{ $err }}</li>
            @endforeach
          </ul>
        </x-portal.alert>
      @endif

      <form action="{{ route('portal.login.attempt') }}" method="POST" class="space-y-5">
        @csrf
        <div>
          <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email"
                 class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-sky-400 focus:ring-sky-400"
                 placeholder="you@example.com" value="{{ old('email') }}">
        </div>

        <div>
          <label for="reference" class="block text-sm font-medium text-slate-700">
            Booking reference <span class="text-slate-400">(optional)</span>
          </label>
          <input id="reference" name="reference" type="text" inputmode="numeric"
                 class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-sky-400 focus:ring-sky-400"
                 placeholder="e.g. DD-12345" value="{{ old('reference') }}">
        </div>

        <button type="submit"
                class="w-full inline-flex items-center justify-center rounded-xl bg-sky-600 px-4 py-3 text-white font-medium hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
          Send login code
        </button>

        <p class="text-xs text-slate-500 text-center">
          We’ll email a one-time code to verify it’s you. No password required.
        </p>
      </form>
    </div>
  </div>

  <div class="mt-6 text-center text-sm text-slate-500">
    Trouble logging in? <a href="{{ route('support.contact') }}" class="text-sky-700 hover:underline">Contact support</a>.
  </div>
@endsection
