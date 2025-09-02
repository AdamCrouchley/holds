// app/Http/Controllers/PaymentMethodController.php
namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class PaymentMethodController extends Controller
{
    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    public function show(Customer $customer)
    {
        // Ensure Stripe customer
        if (!$customer->stripe_customer_id) {
            $sc = $this->stripe()->customers->create([
                'email' => $customer->email,
                'name'  => $customer->name,
                'metadata' => ['app_customer_id' => $customer->id],
            ]);
            $customer->forceFill(['stripe_customer_id' => $sc->id])->save();
        }

        return view('pm/setup', [
            'customer'  => $customer,
            'stripeKey' => config('services.stripe.key'),
        ]);
    }

    /** AJAX: Create a SetupIntent for this customer */
    public function createSetupIntent(Customer $customer)
    {
        abort_unless($customer->stripe_customer_id, 400, 'Stripe customer missing');

        $si = $this->stripe()->setupIntents->create([
            'customer' => $customer->stripe_customer_id,
            'automatic_payment_methods' => ['enabled' => true], // Payment Element (any method)
            'usage' => 'off_session',
            'metadata' => [
                'type' => 'pm_setup',
                'app_customer_id' => $customer->id,
            ],
        ]);

        return response()->json(['clientSecret' => $si->client_secret]);
    }

    /** AJAX: Set default PM and store on our Customer */
    public function setDefault(Request $request, Customer $customer)
    {
        $pm = (string) $request->input('payment_method');
        abort_unless($pm && $customer->stripe_customer_id, 400);

        $stripe = $this->stripe();
        // 1) Set as Stripe default
        $stripe->customers->update($customer->stripe_customer_id, [
            'invoice_settings' => ['default_payment_method' => $pm],
        ]);

        // 2) Persist on our side
        $customer->forceFill(['default_payment_method_id' => $pm])->save();

        return response()->json(['ok' => true]);
    }
}
