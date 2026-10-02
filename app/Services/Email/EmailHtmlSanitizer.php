<?php

namespace App\Services\Email;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes untrusted email HTML for storage and display.
 *
 * Removes scripts, event handlers, styles, forms, iframes and remote images (tracking pixels);
 * links are limited to http(s)/mailto and open safely in a new tab.
 */
class EmailHtmlSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->blockElement('img')
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes([])
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(512 * 1024)
        );
    }

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->sanitizer->sanitize($html);
    }
}
