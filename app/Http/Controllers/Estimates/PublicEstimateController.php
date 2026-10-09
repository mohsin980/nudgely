<?php

namespace App\Http\Controllers\Estimates;

use App\Enums\EstimateDeclineReason;
use App\Exceptions\Estimates\EstimateException;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Services\Estimates\EstimateDocument;
use App\Services\Estimates\EstimateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer's estimate page, reached only through the unguessable link in the estimate email.
 * No account is needed. The link is the only credential: it carries no IDs or organization data,
 * is stored hashed, and is revoked when the estimate is cancelled or replaced by a revision.
 */
class PublicEstimateController extends Controller
{
    public function __construct(private readonly EstimateService $estimates) {}

    public function show(string $token): Response
    {
        $estimate = $this->estimates->findByToken($token) ?? $this->notFound();

        // Someone from the business previewing the link is not the customer viewing it.
        if (auth()->user()?->organization_id === $estimate->organization_id) {
            $this->estimates->expireIfPastValidUntil($estimate);
        } else {
            $estimate = $this->estimates->recordView($estimate);
        }

        return $this->page($estimate, $token);
    }

    public function accept(string $token): RedirectResponse
    {
        $estimate = $this->estimates->findByToken($token) ?? $this->notFound();

        try {
            $this->estimates->accept($estimate);
        } catch (EstimateException $e) {
            return redirect()->route('estimates.public.show', $token)->with('estimate-error', $e->getMessage());
        }

        return redirect()->route('estimates.public.show', $token)->with('estimate-status', 'Thank you! You accepted this estimate. We will be in touch to schedule the work.');
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $estimate = $this->estimates->findByToken($token) ?? $this->notFound();
        $input = $request->validate([
            'reason' => ['nullable', 'string', 'in:'.implode(',', array_column(EstimateDeclineReason::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->estimates->decline($estimate, EstimateDeclineReason::tryFrom((string) ($input['reason'] ?? '')), $input['note'] ?? null);
        } catch (EstimateException $e) {
            return redirect()->route('estimates.public.show', $token)->with('estimate-error', $e->getMessage());
        }

        return redirect()->route('estimates.public.show', $token)->with('estimate-status', 'Thank you for letting us know. You declined this estimate.');
    }

    private function page(Estimate $estimate, string $token): Response
    {
        $organization = $estimate->organization;
        $document = EstimateDocument::for($estimate, $organization);
        // "Ask a question" replies into the estimate's conversation, like replying to the email.
        $replyTo = $estimate->sendMessage?->reply_to ?? $document->businessEmail;

        return $this->private(response()->view('estimates.public', [
            'document' => $document,
            'estimate' => $estimate,
            'token' => $token,
            'askUrl' => $replyTo ? 'mailto:'.$replyTo.'?subject='.rawurlencode('Question about estimate '.$estimate->displayNumber()) : null,
            'declineReasons' => EstimateDeclineReason::cases(),
        ]));
    }

    private function notFound(): never
    {
        // Same response for a wrong, revoked or draft link: nothing reveals whether an estimate exists.
        abort($this->private(response()->view('estimates.not-found', [], 404)));
    }

    private function private(Response $response): Response
    {
        return $response->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
