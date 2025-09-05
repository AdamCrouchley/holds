<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobAccessToken;
use Illuminate\Http\Request;

class PayController extends Controller
{
    /**
     * Show the payment page for a Job via standard route-model binding.
     * Example route: GET /p/job/{job}/pay  (consider making it a signed route)
     */
    public function show(Request $request, Job $job)
    {
        // Optional guards:
        // abort_if($job->status === 'cancelled', 404);
        // abort_unless($request->hasValidSignature(), 403);

        // Minimal context: the pay blade can derive amounts (paid/remaining/holds) from $job.
        return view('portal.pay', [
            'job' => $job,
        ]);
    }

    /**
     * Show the payment page for a Job via a shareable token link.
     * Example route: GET /p/pay/t/{token}
     */
    public function showByToken(string $token)
    {
        $access = JobAccessToken::with('job')->where('token', $token)->firstOrFail();

        // Ensure your JobAccessToken model implements isValid() for expiry/usage/revocation checks.
        abort_unless(method_exists($access, 'isValid') ? $access->isValid() : true, 403);

        $job = $access->job;

        return view('portal.pay', [
            'job'   => $job,
            'token' => $token,
        ]);
    }
}
