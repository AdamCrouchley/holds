<x-app-layout title="Confirm Charge">
  <div class="max-w-lg mx-auto p-6 space-y-4">
    <h1 class="text-xl font-semibold">Confirm your payment</h1>
    <div id="error" class="text-sm text-red-600"></div>
    <button id="confirm" class="rounded bg-sky-600 px-4 py-2 text-white">Confirm payment</button>
    <a href="{{ route('portal.home') }}" class="rounded bg-gray-200 px-3 py-2">Back</a>
  </div>
  <script src="https://js.stripe.com/v3/"></script>
  <script>
    const stripe = Stripe("{{ config('services.stripe.key') ?: env('STRIPE_KEY') }}");
    const clientSecret = "{{ $clientSecret }}";
    document.getElementById('confirm').addEventListener('click', async () => {
      const {error} = await stripe.retrievePaymentIntent(clientSecret);
      if (error) { document.getElementById('error').textContent = error.message; }
      // Stripe will pop 3DS if needed automatically on confirm from server side link
      window.location = "{{ route('portal.home') }}";
    });
  </script>
</x-app-layout>
