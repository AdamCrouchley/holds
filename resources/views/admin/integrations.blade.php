@if (session('status'))
  <div class="mb-3 rounded border border-emerald-200 bg-emerald-50 p-3 text-emerald-700">
    {{ session('status') }}
  </div>
@endif

<form method="POST" action="{{ route('admin.integrations.dreamdrives.sync') }}" class="flex gap-2 items-end">
  @csrf

  <div>
    <label for="from" class="block text-sm text-gray-600">From</label>
    <input type="date" id="from" name="from" class="border rounded px-3 py-2"
           value="{{ now()->toDateString() }}">
  </div>

  <div>
    <label for="to" class="block text-sm text-gray-600">To</label>
    <input type="date" id="to" name="to" class="border rounded px-3 py-2"
           value="{{ now()->addWeek()->toDateString() }}">
  </div>

  <button class="inline-flex items-center rounded bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700">
    Sync Dream Drives
  </button>
</form>
