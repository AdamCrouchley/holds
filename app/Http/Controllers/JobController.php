<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

// Optional mailables (guarded via class_exists).
use App\Mail\PaymentRequestMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\HoldPlacedMail;
use App\Mail\HoldReleasedMail;

class JobController extends Controller
{
    /**
     * POST /admin/jobs/{job}/email-payment-request
     *
     * Accepts optional fields:
     *  - to, cc, bcc (emails)
     *  - subject (string)
     *  - message (string, extra note to include in email body)
     *  - amount_cents (int), type (string: deposit|balance|total|custom)
     */
    public function emailPaymentRequest(Request $request, Job $job)
    {
        // $this->authorize('emailPaymentRequest', $job); // optional

        $data = $request->validate([
            'to'           => ['nullable', 'email'],
            'cc'           => ['nullable', 'email'],
            'bcc'          => ['nullable', 'email'],
            'subject'      => ['nullable', 'string', 'max:150'],
            'message'      => ['nullable', 'string', 'max:4000'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'type'         => ['nullable', 'string', 'in:deposit,balance,total,custom'],
        ]);

        // Recipient resolution
        $to = $data['to']
            ?? optional($job->customer)->email
            ?? $job->customer_email
            ?? null;

        if (!$to) {
            return $this->respond(
                $request,
                422,
                'No recipient email address found. Provide ?to=... or set a customer email on the Job.'
            );
        }

        // Signed pay URL (ensure route has signed middleware if you rely on it)
        $payUrl = URL::signedRoute('portal.pay.show.job', ['job' => $job->getKey()]);

        // Amount / hold hints
        $currency   = $job->currency ?? 'NZD';
        $dueCents   = $data['amount_cents']
            ?? $job->remaining_cents
            ?? $job->amount_due_cents
            ?? $job->due_cents
            ?? null;

        $holdCents  = $job->bond_cents
            ?? $job->hold_cents
            ?? null;

        $reference  = $job->reference ?? ('Job #' . $job->getKey());
        $type       = $data['type'] ?? ($dueCents !== null ? 'total' : 'custom');

        $subject    = $data['subject'] ?? "Payment request for {$reference}";
        $note       = trim((string)($data['message'] ?? ''));

        $customerName = optional($job->customer)->first_name
            ?? optional($job->customer)->name
            ?? $job->customer_name
            ?? 'there';

        $brand = $job->brand ?? (object) [
            'short_name'     => 'Dream Drives',
            'email_logo_url' => null,
        ];

        try {
            if (class_exists(PaymentRequestMail::class)) {
                // Prefer your dedicated Mailable if present
                $mailable = new PaymentRequestMail($job, $payUrl, $dueCents, $type, $brand);

                Mail::to($to)
                    ->when(!empty($data['cc']), fn($m) => $m->cc($data['cc']))
                    ->when(!empty($data['bcc']), fn($m) => $m->bcc($data['bcc']))
                    ->send($mailable->subject($subject)->with(['note' => $note, 'holdCents' => $holdCents]));
            } else {
                // Inline HTML fallback (no view required)
                $html = $this->buildEmailHtml(
                    customerName: $customerName,
                    reference: $reference,
                    currency: $currency,
                    dueCents: $dueCents,
                    holdCents: $holdCents,
                    payUrl: $payUrl,
                    note: $note,
                );

                Mail::html($html, function ($m) use ($to, $subject, $data) {
                    $m->to($to)->subject($subject);
                    if (!empty($data['cc']))  $m->cc($data['cc']);
                    if (!empty($data['bcc'])) $m->bcc($data['bcc']);
                });
            }

            Log::info('Payment request email sent', [
                'job_id'  => $job->getKey(),
                'to'      => $to,
                'subject' => $subject,
                'amount'  => $dueCents,
                'type'    => $type,
            ]);

            return $this->respond($request, 200, "Payment request sent to {$to}.");
        } catch (\Throwable $e) {
            Log::error('Payment request email failed', [
                'job_id'  => $job->getKey(),
                'to'      => $to,
                'error'   => $e->getMessage(),
            ]);

            return $this->respond($request, 500, 'Failed to send email: ' . $e->getMessage());
        }
    }

    /* ============================== MAIL HELPERS ============================== */

    /**
     * Call this right after you mark a Payment as succeeded.
     * Example from your Payment logic:
     *   $payment->status = 'succeeded';
     *   $payment->save();
     *   JobController::notifyReceipt($job, $payment);
     */
    public static function notifyReceipt(Job $job, $payment): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(PaymentReceiptMail::class)) {
                Mail::to($to)->send(new PaymentReceiptMail($job, $payment, $job->brand ?? null));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send PaymentReceiptMail', [
                'job_id'  => $job->getKey(),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Call this after placing an authorization hold.
     * $releaseEta is a human string like 'usually 7–10 days'.
     */
    public static function notifyHoldPlaced(Job $job, int $holdCents, string $currency, ?string $releaseEta = 'usually 7–10 days'): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(HoldPlacedMail::class)) {
                Mail::to($to)->send(
                    new HoldPlacedMail($job, $holdCents, $currency, $job->brand ?? null, $releaseEta)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send HoldPlacedMail', [
                'job_id'  => $job->getKey(),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Call this after releasing a previous authorization hold.
     */
    public static function notifyHoldReleased(Job $job, int $holdCents, string $currency): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(HoldReleasedMail::class)) {
                Mail::to($to)->send(
                    new HoldReleasedMail($job, $holdCents, $currency, $job->brand ?? null)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send HoldReleasedMail', [
                'job_id'  => $job->getKey(),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /* ============================== UTILITIES ================================ */

    private function money(?int $cents, string $currency = 'NZD'): ?string
    {
        if ($cents === null) return null;
        $amount = number_format($cents / 100, 2, '.', ',');
        return "{$currency} {$amount}";
    }

    private function buildEmailHtml(
        string $customerName,
        string $reference,
        string $currency,
        ?int $dueCents,
        ?int $holdCents,
        string $payUrl,
        string $note = ''
    ): string {
        $due  = $this->money($dueCents, $currency);
        $hold = $this->money($holdCents, $currency);

        $rows = [];
        if ($due !== null) {
            $rows[] = "<tr><td style=\"padding:6px 0;color:#111\">Amount due:</td><td style=\"padding:6px 0;color:#111;font-weight:600\">{$due}</td></tr>";
        }
        if ($hold !== null) {
            $rows[] = "<tr><td style=\"padding:6px 0;color:#111\">Bond/hold:</td><td style=\"padding:6px 0;color:#111\">{$hold}</td></tr>";
        }
        $rowsHtml = implode('', $rows);

        $noteHtml = $note !== '' ? "<p style=\"margin:16px 0;color:#111;white-space:pre-line;\">{$this->escape($note)}</p>" : '';

        $refHtml  = $this->escape($reference);
        $nameHtml = $this->escape($customerName);

        return <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f7f9">
  <div style="max-width:640px;margin:0 auto;padding:24px">
    <div style="background:#ffffff;border-radius:12px;padding:24px;border:1px solid #e5e7eb">
      <h2 style="margin:0 0 12px;font-size:20px;color:#111">Payment request</h2>
      <p style="margin:0 0 8px;color:#111">Hi {$nameHtml},</p>
      <p style="margin:0 0 16px;color:#111">We’ve prepared a payment link for <strong>{$refHtml}</strong>. You can pay securely via the button below.</p>

      <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:12px 0 0">{$rowsHtml}</table>

      {$noteHtml}

      <p style="margin:20px 0">
        <a href="{$payUrl}" style="display:inline-block;padding:12px 18px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">
          Pay now
        </a>
      </p>

      <p style="margin:16px 0;color:#6b7280;font-size:12px">If the button doesn’t work, copy and paste this link into your browser:<br>
        <span style="word-break:break-all;color:#374151">{$this->escape($payUrl)}</span>
      </p>

      <p style="margin:24px 0 0;color:#111">Thanks,<br>The Team</p>
    </div>
  </div>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function respond(Request $request, int $status, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => $status < 400,
                'message' => $message,
            ], $status);
        }
        return back()->with($status < 400 ? 'status' : 'error', $message);
    }
}
