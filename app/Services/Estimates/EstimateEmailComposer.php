<?php

namespace App\Services\Estimates;

use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Organization;
use App\Services\Automation\EmailTemplateRenderer;

/**
 * The estimate email: a short message from a fixed template (controlled {{variables}} only,
 * nothing evaluated), the estimate document, and the customer's secure link.
 */
class EstimateEmailComposer
{
    public const SUBJECT = 'Estimate {{estimate.number}} from {{business.name}}';

    public const BODY = "Hi {{customer.first_name}},\n\nPlease find your estimate for {{estimate.title}}.\n\nEstimate: {{estimate.number}}\nTotal: {{estimate.total}}\nValid until: {{estimate.valid_until}}\n\nIf you have any questions, please reply to this email.\n\nBest,\n{{business.name}}";

    public function __construct(private readonly EmailTemplateRenderer $templates) {}

    /**
     * @return array{0: string, 1: string, 2: string} Subject, HTML and plain text.
     *
     * @throws InvalidEmailTemplateException
     */
    public function compose(Estimate $estimate, Customer $customer, Organization $organization): array
    {
        $subject = $this->templates->render(self::SUBJECT, $customer, $organization, $estimate);
        $message = $this->templates->render(self::BODY, $customer, $organization, $estimate);
        $document = EstimateDocument::for($estimate, $organization);
        $url = $estimate->publicUrl();

        $html = view('estimates.email', ['message' => $message, 'document' => $document, 'url' => $url])->render();

        $lines = $document->items->map(fn ($item) => "- {$item->description}: {$item->displayQuantity()} × {$item->money('unit_price', $estimate->currency)} = {$item->money('amount', $estimate->currency)}")->implode("\n");
        $text = $message."\n\nView, accept or decline your estimate:\n{$url}\n\n{$lines}\n\nTotal: {$estimate->money('total')}";

        return [$subject, $html, $text];
    }
}
