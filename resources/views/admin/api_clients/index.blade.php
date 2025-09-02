@extends('layouts.app')

@section('title','API Keys')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-semibold mb-6">API Keys</h1>

    @if(session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-green-700 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.api-keys.store') }}"
          class="mb-8 flex items-end gap-3">
        @csrf
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700">Name</label>
            <input name="name" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Zapier — Operations"/>
            @error('name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <button class="rounded-lg bg-indigo-600 text-white px-4 py-2 hover:bg-indigo-700">Create</button>
    </form>

    <div class="space-y-3">
        @forelse($clients as $c)
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-medium">{{ $c->name }}</div>
                        <div class="text-xs text-gray-500">Created {{ $c->created_at->diffForHumans() }}</div>
                        <div class="mt-2 text-xs break-all">
                            <span class="px-2 py-1 rounded bg-gray-50 border">{{ $c->token }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('admin.api-keys.toggle', $c) }}">
                            @csrf
                            <button class="rounded-lg border px-3 py-1 text-sm {{ $c->enabled ? 'border-yellow-300 text-yellow-700' : 'border-green-300 text-green-700' }}">
                                {{ $c->enabled ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.api-keys.regenerate', $c) }}">
                            @csrf
                            <button class="rounded-lg border px-3 py-1 text-sm">Regenerate</button>
                        </form>
                        <form method="POST" action="{{ route('admin.api-keys.destroy', $c) }}"
                              onsubmit="return confirm('Delete this key?');">
                            @csrf @method('DELETE')
                            <button class="rounded-lg border border-red-300 px-3 py-1 text-sm text-red-700">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No API keys yet.</p>
        @endforelse
    </div>
</div>
@endsection
