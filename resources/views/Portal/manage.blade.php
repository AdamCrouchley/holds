<x-layouts.app :title="'Manage payment method'">
  <div class="card">
    <h1 class="text-xl font-semibold mb-2">Manage your card</h1>
    @if($currentPm)
      <p class="muted mb-2">You have a card on file. You can replace it below.</p>
    @else
      <p class="muted mb-2">Add a card on file for balances and the security hold.</p>
    @endif

    <form id="setup-form" method="POST" action="{{ route('portal.manage.submit', $booking->portal_token) }}">
      @csrf
      <div id="payment-element" class="mb-3"></div>
      <button id="submit" class="btn">Save card</button>
      <div id="message" class="mt-3 muted"></div>
    </form>
  </div>

  <script src="https://js.stripe.com/v3/"></script>
  <script>
    const stripe = Stripe(@json($publishableKey));
    const clientSecret = @json($clientSecret);
    const elements = stripe.elements({ clientSecret });
    const paymentEl = elements.create("payment");
    paymentEl.mount("#payment-element");

    document.getElementById('setup-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      document.getElementById('submit').disabled = true;

      const { error } = await stripe.confirmSetup({
        elements,
        confirmParams: { return_url: "{{ route('home') }}" }
      });

      if (error) {
        document.getElementById('message').textContent = error.message || "Something went wrong.";
        document.getElementById('submit').disabled = false;
      } else {
        document.getElementById('message').textContent = "Saving…";
      }
    });
  </script>
</x-layouts.app>
