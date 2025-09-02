@php($title = 'Contact Support')

@extends('layouts.portal')

@section('content')
  <div class="rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 overflow-hidden">
    <div class="px-6 py-6 md:px-8 md:py-8 border-b border-slate-100">
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
      <p class="mt-1 text-sm text-slate-500">
        Need help with your booking or portal login? Fill in the form below and our team will get back to you as soon as possible.
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

      <form action="{{ route('support.contact.send') }}" method="POST" class="space-y-5">
        @csrf

        {{-- Name --}}
        <div>
          <label for="name" class="block text-sm font-medium text-slate-700">Name</label>
          <input id="name" name="name" type="text" required
                 class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-sky-400 focus:ring-sky-400"
                 placeholder="Your full name" value="{{ old('name') }}">
        </div>

        {{-- Email --}}
        <div>
          <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email"
                 class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-sky-400 focus:ring-sky-400"
                 placeholder="you@example.com" value="{{ old('email') }}">
        </div>

        {{-- Message --}}
        <div>
          <label for="message" class="block text-sm font-medium text-slate-700">Message</label>
          <textarea id="message" name="message" rows="5" required
                    class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-sky-400 focus:ring-sky-400"
                    placeholder="How can we help?">{{ old('message') }}</textarea>
        </div>

        {{-- Submit --}}
        <button type="submit"
                class="w-full inline-flex items-center justify-center rounded-xl bg-sky-600 px-4 py-3 text-white font-medium hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
          Send message
        </button>
      </form>
    </div>
  </div>
@endsection
