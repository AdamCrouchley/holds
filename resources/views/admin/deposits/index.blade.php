{{-- resources/views/admin/deposits/index.blade.php --}}
@php /** @var \Illuminate\Pagination\LengthAwarePaginator $deposits */ @endphp
<x-layout>
  <h1>Deposits / Holds</h1>

  <form method="get" class="mb-4">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search..." />
    <select name="status">
      <option value="">Any status</option>
      @foreach(['authorized','captured','released','canceled','failed'] as $s)
        <option value="{{ $s }}" @selected(request('status')===$s)>{{ $s }}</option>
      @endforeach
    </select>
    <button type="submit">Filter</button>
  </form>

  <table border="1" cellpadding="6">
    <thead>
      <tr>
        <th>ID</th>
        <th>PI</th>
        <th>Status</th>
        <th>Amount</th>
        <th>Currency</th>
        <th>Booking</th>
        <th>Customer</th>
        <th>Updated</th>
      </tr>
    </thead>
    <tbody>
      @forelse($deposits as $d)
        <tr>
          <td>{{ $d->id }}</td>
          <td>{{ $d->stripe_payment_intent_id ?? $d->stripe_payment_intent }}</td>
          <td>{{ $d->status }}</td>
          <td>{{ number_format(($d->amount ?? 0)/100, 2) }}</td>
          <td>{{ strtoupper($d->currency ?? 'nzd') }}</td>
          <td>{{ $d->booking?->id }}</td>
          <td>{{ $d->customer?->email }}</td>
          <td>{{ $d->updated_at }}</td>
        </tr>
      @empty
        <tr><td colspan="8">No results</td></tr>
      @endforelse
    </tbody>
  </table>

  {{ $deposits->links() }}
</x-layout>
