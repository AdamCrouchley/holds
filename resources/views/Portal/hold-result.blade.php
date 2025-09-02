<x-layouts.app :title="'Security deposit status'">
  <div class="card">
    @if($success)
      <h1 class="text-xl font-semibold mb-2">Deposit authorised</h1>
      <p class="muted">Booking <strong>{{ $booking->reference }}</strong>. {{ $message ?? '' }}</p>
    @else
      <h1 class="text-xl font-semibold mb-2">We couldn’t authorise the hold</h1>
      <p class="muted">{{ $message ?? 'Please try again or use a different card.' }}</p>
    @endif
  </div>
</x-layouts.app>
