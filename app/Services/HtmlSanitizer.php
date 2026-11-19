<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans every piece of rich-text HTML (page content, ACF fields, portfolio descriptions)
 * before it is stored, so the React front-end never receives <script>, inline event
 * handlers (onclick=...), javascript: URLs, iframes from unknown hosts, etc.  (XSS protection)
 */
class HtmlSanitizer
{
    private SymfonySanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('iframe', ['src', 'width', 'height', 'title', 'allowfullscreen'])
            ->allowAttribute('class', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            // Only allow video embeds from trusted hosts
            ->allowMediaHosts([
                'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com',
                parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            ])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(500_000);

        $this->sanitizer = new SymfonySanitizer($config);
    }

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->sanitizer->sanitize($html);
    }
}
