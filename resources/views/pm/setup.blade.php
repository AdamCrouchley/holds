{{-- resources/views/pm/setup.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">
            Add payment method – {{ $customer->name ?: $customer->email }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-xl space-y-4">
            <div class="rounded-lg border bg-white p-4">
                <p class="text-sm text-gray-600">Saved card will be used for bond holds and future charges.</p>
            </div>

            <div id="payment-element"></div>
            <button id="saveBtn" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-white">Save card</button>
            <div id="error" class="mt-3 text-sm text-rose-600"></div>
            <div id="ok" class="mt-3 text-sm text-green-700"></div>
        </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe(@json($stripeKey));
        let elements, clientSecret;

        async function ensureSetupIntent() {
            const res = await fetch(@json(route('customers.pm.intent', $customer)), {
                method: 'POST', headers: {'X-CSRF-TOKEN': @json(csrf_token())}
            });
            const data = await res.json();
            clientSecret = data.clientSecret;

            elements = stripe.elements({clientSecret});
            const paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');
        }
        ensureSetupIntent();

        document.getElementById('saveBtn')?.addEventListener('click', async () => {
            document.getElementById('error').textContent = '';
            const {setupIntent, error} = await stripe.confirmSetup({
                elements,
                confirmParams: {},
            });
            if (error) {
                document.getElementById('error').textContent = error.message ?? 'Unable to save card.';
                return;
            }
            // Mark as default in Stripe + store in DB
            const res = await fetch(@json(route('customers.pm.default', $customer)), {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN': @json(csrf_token())},
                body: JSON.stringify({payment_method: setupIntent.payment_method})
            });
            if (res.ok) {
                document.getElementById('ok').textContent = 'Card saved and set as default.';
            } else {
                document.getElementById('error').textContent = 'Card saved on Stripe, but failed to set default in app.';
            }
        });
    </script>
</x-app-layout>
