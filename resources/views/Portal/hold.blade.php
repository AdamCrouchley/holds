<x-layouts.app :title="'Authorise refundable security deposit'">
  <div class="card">
    <h1 class="text-xl font-semibold mb-2">Authorise refundable security deposit</h1>
    <p class="muted mb-4">
      Booking <strong>{{ $booking->reference }}</strong>.
      Hold amount: <strong>{{ number_format($booking->hold_amount/100, 2) }} {{ $booking->currency }}</strong>
    </p>

    <form id="hold-form" method="POST" action="{{ route('portal.hold.submit', $booking->portal_token) }}">
      @csrf
      <div id="payment-element" class="mb-3"></div>
      <label style="display:flex;gap:.5rem;align-items:center;margin:.5rem 0;">
        <input type="checkbox" id="consent" required>
        <span class="muted">I authorise a refundable hold for the duration of my rental.</span>
      </label>
      <button id="submit" class="btn">Authorise hold</button>
      <div id="payment-message" class="mt-3 muted"></div>
    </form>
  </div>

  <script src="https://js.stripe.com/v3/"></script>
  <script>
    const stripe = Stripe(@json($publishableKey));
    const clientSecret = @json($clientSecret);
    const elements = stripe.elements({ clientSecret });
    const paymentEl = elements.create("payment");
    paymentEl.mount("#payment-element");

    document.getElementById('hold-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!document.getElementById('consent').checked) return;

      document.getElementById('submit').disabled = true;

      const { error } = await stripe.confirmPayment({
        elements,
        confirmParams: { return_url: "{{ route('home') }}" }
      });

      if (error) {
        document.getElementById('payment-message').textContent = error.message || "Something went wrong.";
        document.getElementById('submit').disabled = false;
      } else {
        document.getElementById('payment-message').textContent = "Processing…";
      }
    });
  </script>
</x-layouts.app>
