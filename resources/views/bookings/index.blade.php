<x-layouts.app :title="'Bookings'">
  <div class="card">
    <h1 class="text-xl font-semibold mb-4">Bookings</h1>

    <table style="width:100%; border-collapse: collapse;">
      <thead>
        <tr style="text-align:left; border-bottom:1px solid #eee;">
          <th>Ref</th>
          <th>Customer</th>
          <th>Dates</th>
          <th>Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      @foreach($bookings as $b)
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td>{{ $b->reference }}</td>
          <td>{{ $b->customer?->first_name }} {{ $b->customer?->last_name }}</td>
          <td>{{ $b->start_at->format('Y-m-d') }} → {{ $b->end_at->format('Y-m-d') }}</td>
          <td>{{ number_format($b->total_amount/100, 2) }} {{ $b->currency }}</td>
          <td><a href="{{ route('bookings.show', $b) }}">Open</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>

    <div style="margin-top:12px;">{{ $bookings->links() }}</div>
  </div>
</x-layouts.app>

