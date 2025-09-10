<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

// Optional mailables (guarded via class_exists)
use App\Mail\PaymentRequestMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\HoldPlacedMail;
use App\Mail\HoldReleasedMail;

class JobController extends Controller
{
    /**
     * ROUTE HANDLER (instance method)
     * POST /admin/jobs/{job}/email-payment-request
     */
    public function emailPaymentRequest(Request $request, Job $job): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'to'           => ['nullable', 'email'],
            'cc'           => ['nullable', 'email'],
            'bcc'          => ['nullable', 'email'],
            'subject'      => ['nullable', 'string', 'max:150'],
            'message'      => ['nullable', 'string', 'max:4000'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'type'         => ['nullable', 'string', 'in:deposit,balance,total,custom'],
        ]);

        [$ok, $msg] = $this->sendPaymentRequestInternal($job, $data);

        return $this->respond($request, $ok ? 200 : 500, $msg);
    }

    /**
     * STATIC WRAPPER (callable from jobs/commands/other controllers)
     * Example: JobController::emailPaymentRequestStatic($job, ['to' => 'x@y.com'])
     */
public static function emailPaymentRequestStatic(Job $job, array $data = []): array
{
    try {
        // Use a lightweight instance to reuse internal logic
        $self = app(static::class);
        [$ok, $msg] = $self->sendPaymentRequestInternal($job, $data);

        if (!$ok) {
            Log::error('emailPaymentRequestStatic failed', [
                'job_id' => $job->getKey(),
                'msg'    => $msg,
            ]);
        }

        return [$ok, $msg];
    } catch (\Throwable $e) {
        Log::error('emailPaymentRequestStatic threw', [
            'job_id' => $job->getKey(),
            'err'    => $e->getMessage(),
        ]);
        return [false, 'Mailer threw: ' . $e->getMessage()];
    }
}


    /* ============================== MAIL HELPERS ============================== */

    /** Shared logic used by both instance route and static wrapper. */
    private function sendPaymentRequestInternal(Job $job, array $data): array
    {
        // Resolve recipient
        $to = $data['to']
            ?? optional($job->customer)->email
            ?? $job->customer_email
            ?? null;

        if (!$to) {
            return [false, 'No recipient email address found. Provide `to` or set a customer email on the Job.'];
        }

        // Build link (signed route to your job pay page)
        $payUrl   = URL::signedRoute('portal.pay.show.job', ['job' => $job->getKey()]);
        $currency = $job->currency ?? 'NZD';
        $dueCents = $data['amount_cents']
            ?? $job->remaining_cents
            ?? $job->amount_due_cents
            ?? $job->due_cents
            ?? null;

        $holdCents = $job->bond_cents ?? $job->hold_cents ?? null;

        $reference = $job->reference ?? ('Job #' . $job->getKey());
        $type      = $data['type'] ?? ($dueCents !== null ? 'total' : 'custom');

        $subject = $data['subject'] ?? "Payment request for {$reference}";
        $note    = trim((string)($data['message'] ?? ''));

        $customerName = optional($job->customer)->first_name
            ?? optional($job->customer)->name
            ?? $job->customer_name
            ?? 'there';

        // IMPORTANT CHANGE: do not pass a Brand object (avoid missing Brand model)
        $brand = null;

        try {
            if (class_exists(PaymentRequestMail::class)) {
                $mailable = (new PaymentRequestMail($job, $payUrl, $dueCents, $type, $brand))
                    ->subject($subject)
                    ->with(['note' => $note, 'holdCents' => $holdCents, 'currency' => $currency]);

                $pending = Mail::to($to);
                if (!empty($data['cc']))  $pending->cc($data['cc']);
                if (!empty($data['bcc'])) $pending->bcc($data['bcc']);
                $pending->send($mailable);
            } else {
                // Fallback inline HTML
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

            return [true, "Payment request sent to {$to}."];
        } catch (\Throwable $e) {
            Log::error('Payment request email failed', [
                'job_id' => $job->getKey(),
                'to'     => $to,
                'error'  => $e->getMessage(),
            ]);
            return [false, 'Failed to send email: ' . $e->getMessage()];
        }
    }

    /**
     * Receipt (static helper you already call elsewhere)
     */
    public static function notifyReceipt(Job $job, $payment): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(PaymentReceiptMail::class)) {
                // IMPORTANT CHANGE: pass brand = null
                Mail::to($to)->send(new PaymentReceiptMail($job, $payment, null));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send PaymentReceiptMail', [
                'job_id' => $job->getKey(),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public static function notifyHoldPlaced(Job $job, int $holdCents, string $currency, ?string $releaseEta = 'usually 7–10 days'): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(HoldPlacedMail::class)) {
                // IMPORTANT CHANGE: pass brand = null
                Mail::to($to)->send(
                    new HoldPlacedMail($job, $holdCents, $currency, null, $releaseEta)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send HoldPlacedMail', [
                'job_id' => $job->getKey(),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public static function notifyHoldReleased(Job $job, int $holdCents, string $currency): void
    {
        $to = optional($job->customer)->email ?? $job->customer_email ?? null;
        if (!$to) return;

        try {
            if (class_exists(HoldReleasedMail::class)) {
                // IMPORTANT CHANGE: pass brand = null
                Mail::to($to)->send(
                    new HoldReleasedMail($job, $holdCents, $currency, null)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send HoldReleasedMail', [
                'job_id' => $job->getKey(),
                'error'  => $e->getMessage(),
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
        $paySafe  = $this->escape($payUrl);

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
        <a href="{$paySafe}" style="display:inline-block;padding:12px 18px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">
          Pay now
        </a>
      </p>

      <p style="margin:16px 0;color:#6b7280;font-size:12px">If the button doesn’t work, copy and paste this link into your browser:<br>
        <span style="word-break:break-all;color:#374151">{$paySafe}</span>
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

    private function respond(Request $request, int $status, string $message): JsonResponse|RedirectResponse
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
