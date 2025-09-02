<?php
// app/Services/StripeHelpers.php

namespace App\Services;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeHelpers
{
    /**
     * Ensure there is a Stripe Customer for the given user-like model and keep it in sync.
     *
     * @param  object       $user   Must expose: email, first_name/last_name or name, phone (optional), stripe_customer_id (optional)
     * @param  StripeClient $stripe
     * @param  array<string,mixed> $opts  ['metadata' => [...], 'address' => [...], 'shipping' => [...], 'forceUpdate' => bool]
     * @return string Stripe customer ID
     *
     * @throws ApiErrorException
     */
    public static function ensureStripeCustomer(object $user, StripeClient $stripe, array $opts = []): string
    {
        $metadata    = (array)($opts['metadata'] ?? []);
        $address     = isset($opts['address'])  && is_array($opts['address'])  ? $opts['address']  : null; // ['city'=>..., 'country'=>..., 'line1'=>..., 'postal_code'=>..., 'state'=>...]
        $shipping    = isset($opts['shipping']) && is_array($opts['shipping']) ? $opts['shipping'] : null; // ['name'=>..., 'phone'=>..., 'address'=>[...]]
        $forceUpdate = (bool)($opts['forceUpdate'] ?? false);

        $email = trim((string)($user->email ?? '')) ?: null;
        $name  = self::composeName($user);
        $phone = trim((string)($user->phone ?? '')) ?: null;

        // 1) If we already have a local Stripe customer id, verify it exists; if not, clear it.
        if (!empty($user->stripe_customer_id)) {
            try {
                $cust = $stripe->customers->retrieve($user->stripe_customer_id, []);
            } catch (ApiErrorException $e) {
                // If the referenced customer is gone (deleted/invalid), fall through to create/lookup.
                $cust = null;
                $user->stripe_customer_id = null;
                // Do NOT save yet; we will save once we create/resolve.
            }

            if ($cust && empty($cust->deleted)) {
                // Optionally sync changes back to Stripe if local data changed or forceUpdate is set.
                $update = [];
                if ($forceUpdate || ($email && $cust->email !== $email))   $update['email'] = $email;
                if ($forceUpdate || ($name  && $cust->name  !== $name))    $update['name']  = $name;
                if ($forceUpdate || ($phone && $cust->phone !== $phone))   $update['phone'] = $phone;
                if ($metadata)  $update['metadata'] = array_merge((array) ($cust->metadata ?? []), $metadata);
                if ($address)   $update['address']  = $address;
                if ($shipping)  $update['shipping'] = $shipping;

                if (!empty($update)) {
                    $stripe->customers->update($cust->id, $update);
                }

                return $cust->id;
            }
        }

        // 2) No valid local Stripe ID. Try to find by email to avoid duplicates.
        if ($email) {
            // Prefer Search API if available to your account; else fallback to list+filter.
            try {
                $existing = $stripe->customers->search([
                    'query' => "email:'{$email}'",
                    'limit' => 1,
                ]);
                $match = $existing->data[0] ?? null;
            } catch (\Throwable) {
                // Fallback: list does not *guarantee* filtering by email, but we'll filter locally.
                $listed = $stripe->customers->all(['limit' => 10]);
                $match = null;
                foreach ($listed->data as $c) {
                    if (!empty($c->email) && strcasecmp($c->email, $email) === 0) {
                        $match = $c;
                        break;
                    }
                }
            }

            if ($match && empty($match->deleted)) {
                $cust = $match;

                // Optionally update name/phone/metadata if we have better info locally.
                $update = [];
                if ($name  && $cust->name  !== $name)   $update['name']  = $name;
                if ($phone && $cust->phone !== $phone)  $update['phone'] = $phone;
                if ($metadata)  $update['metadata'] = array_merge((array) ($cust->metadata ?? []), $metadata);
                if ($address)   $update['address']  = $address;
                if ($shipping)  $update['shipping'] = $shipping;

                if (!empty($update)) {
                    $cust = $stripe->customers->update($cust->id, $update);
                }

                $user->stripe_customer_id = $cust->id;
                if (method_exists($user, 'save')) {
                    $user->save();
                }

                return $cust->id;
            }
        }

        // 3) Create a new customer
        $payload = [
            'email' => $email,
            'name'  => $name,
        ];
        if ($phone)     $payload['phone']    = $phone;
        if ($metadata)  $payload['metadata'] = $metadata;
        if ($address)   $payload['address']  = $address;
        if ($shipping)  $payload['shipping'] = $shipping;

        $cust = $stripe->customers->create($payload);

        $user->stripe_customer_id = $cust->id;
        if (method_exists($user, 'save')) {
            $user->save();
        }

        return $cust->id;
    }

    /**
     * Convenience helper to prefer first/last name, else a generic "name" attribute.
     */
    private static function composeName(object $user): ?string
    {
        $first = trim((string)($user->first_name ?? ''));
        $last  = trim((string)($user->last_name ?? ''));
        $name  = trim(($first . ' ' . $last)) ?: null;

        if (!$name) {
            $fromName = trim((string)($user->name ?? ''));
            $name = $fromName ?: null;
        }

        return $name ?: null;
    }
}
