<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // extend Authenticatable for auth guard
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class Customer extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /** @var string[] */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',

        // Payments
        'default_payment_method_id',
        'stripe_customer_id',

        // Address
        'address_line1',
        'address_line2',
        'address_city',
        'address_region',
        'address_postcode',
        'address_country',

        // Portal (passwordless login + long-lived portal token)
        'portal_token',              // long-lived token for /p/* flows
        'login_token',               // hashed one-time token (never store raw)
        'login_token_expires_at',    // datetime
        'portal_last_login_at',      // datetime
        'portal_last_seen_at',       // datetime (optional heartbeat)
        'portal_timezone',           // optional (e.g. 'Pacific/Auckland')
        'portal_magic_redirect',     // optional: where to land after login

        // Freeform notes/metadata
        'meta',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'meta'                   => 'array',
        'login_token_expires_at' => 'datetime',
        'portal_last_login_at'   => 'datetime',
        'portal_last_seen_at'    => 'datetime',
    ];

    /** @var string[] */
    protected $hidden = [
        'login_token',
        'stripe_customer_id',
        'default_payment_method_id',
        'meta',
        // 'portal_token', // uncomment to hide in API responses if treated as a secret
    ];

    /** @var string[] */
    protected $appends = [
        'name',
    ];

    // ---------------------------------------------------------------------
    // Auth integration (passwordless)
    // ---------------------------------------------------------------------

    /**
     * No password is used for customer portal auth (magic-link only).
     * Returning an empty string avoids null checks in some drivers.
     */
    public function getAuthPassword()
    {
        return '';
    }

    /**
     * Disable "remember me" for customers.
     */
    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op (remember tokens disabled)
    }

    public function getRememberTokenName(): string
    {
        // returning an empty name effectively disables remember token usage
        return '';
    }

    /**
     * Ensure notifications (if used) go to the customer email.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    // ---------------------------------------------------------------------
    // Scopes & finders
    // ---------------------------------------------------------------------

    /** Case-insensitive email lookup */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
    }

    public static function findByEmail(string $email): ?self
    {
        return static::whereEmail($email)->first();
    }

    /** Quick finder by portal token. */
    public function scopeWherePortalToken(Builder $query, string $token): Builder
    {
        return $query->where('portal_token', $token);
    }

    public static function findByPortalToken(string $token): ?self
    {
        return static::wherePortalToken($token)->first();
    }

    // ---------------------------------------------------------------------
    // Mutators
    // ---------------------------------------------------------------------

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value ? mb_strtolower(trim($value)) : null;
    }

    // ---------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------

    /** Full name; falls back sensibly */
    public function getNameAttribute(): string
    {
        $first = $this->first_name ?? '';
        $last  = $this->last_name ?? '';
        $full  = trim($first . ' ' . $last);

        if ($full !== '') {
            return $full;
        }

        return (string)($this->attributes['name'] ?? ($this->email ?? ''));
    }

    /** A short “Adam C.” style label */
    public function getShortNameAttribute(): string
    {
        $first = trim((string)$this->first_name);
        $last  = trim((string)$this->last_name);

        if ($first !== '' && $last !== '') {
            return $first . ' ' . mb_substr($last, 0, 1) . '.';
        }

        return $this->name;
    }

    // ---------------------------------------------------------------------
    // Portal (magic-link) helpers
    // ---------------------------------------------------------------------

    /**
     * Ensure a long-lived portal token exists (used by /p/dash/{token}, /p/pay/{token}).
     */
    public function ensurePortalToken(int $length = 40): string
    {
        if (blank($this->portal_token)) {
            $this->portal_token = Str::random($length);
            $this->save();
        }
        return $this->portal_token;
    }

    /**
     * Rotate the portal token (invalidates old links).
     */
    public function rotatePortalToken(int $length = 40): string
    {
        $this->portal_token = Str::random($length);
        $this->save();

        return $this->portal_token;
    }

    /**
     * Issue a fresh one-time login token (opaque raw token returned),
     * hash it for storage, and set expiry.
     *
     * @param \DateTimeInterface|int|null $expiresIn Absolute time or seconds from now (default 15 minutes)
     * @return string The RAW token to embed in the login URL (store only the hash!)
     */
    public function issueLoginToken($expiresIn = null): string
    {
        $raw  = Str::random(64);
        $hash = hash('sha256', $raw);

        if ($expiresIn instanceof \DateTimeInterface) {
            $expiresAt = Carbon::instance($expiresIn);
        } elseif (is_int($expiresIn)) {
            $expiresAt = now()->addSeconds($expiresIn);
        } else {
            $expiresAt = now()->addMinutes(15);
        }

        $this->forceFill([
            'login_token'            => $hash,
            'login_token_expires_at' => $expiresAt,
        ])->save();

        return $raw;
    }

    /**
     * Validate a presented raw token against the stored hash + expiry.
     * If valid, it clears the token and stamps last login.
     */
    public function consumeLoginToken(string $rawToken): bool
    {
        if (!$this->login_token || !$this->login_token_expires_at) {
            return false;
        }

        if (now()->greaterThan($this->login_token_expires_at)) {
            $this->clearLoginToken();
            return false;
        }

        $hash = hash('sha256', $rawToken);

        if (!hash_equals($this->login_token, $hash)) {
            return false;
        }

        $this->forceFill([
            'login_token'            => null,
            'login_token_expires_at' => null,
            'portal_last_login_at'   => now(),
            'portal_last_seen_at'    => now(),
        ])->save();

        return true;
    }

    /** Clear any outstanding magic link token. */
    public function clearLoginToken(): void
    {
        $this->forceFill([
            'login_token'            => null,
            'login_token_expires_at' => null,
        ])->save();
    }

    /** Mark a heartbeat/last seen (e.g., on each portal page hit). */
    public function touchPortalSeen(): void
    {
        $this->forceFill([
            'portal_last_seen_at' => now(),
        ])->save();
    }

    /**
     * Create a full magic-link URL for the controller to send.
     * Example route: route('portal.magic.consume', ['token' => $rawToken, 'email' => $this->email])
     */
    public function buildMagicLoginUrl(string $routeName, array $params = []): string
    {
        $raw = $this->issueLoginToken();
        $payload = array_merge(['token' => $raw, 'email' => $this->email], $params);

        return route($routeName, $payload);
    }
}
