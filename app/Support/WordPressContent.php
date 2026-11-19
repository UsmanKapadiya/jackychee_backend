<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Helpers to turn raw WordPress post_content into clean HTML for the new site.
 */
final class WordPressContent
{
    /** Remove WordPress shortcodes such as [easy_portfolio] or [caption ...]...[/caption]. */
    public static function stripShortcodes(string $html): string
    {
        // keep the inner text of enclosing caption shortcodes
        $html = preg_replace('#\[caption[^\]]*\](.*?)\[/caption\]#is', '$1', $html) ?? $html;

        return preg_replace('#\[/?[a-zA-Z_][a-zA-Z0-9_\-]*(?:\s[^\]]*)?\]#', '', $html) ?? $html;
    }

    /** Minimal version of WordPress' wpautop(): blank lines become paragraphs, single newlines <br>. */
    public static function autop(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $block = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe)';

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [];
        $out = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match('#^</?'.$block.'[\s/>]#i', $p)) {
                $out .= $p."\n";
            } else {
                $out .= '<p>'.preg_replace('#(?<!>)\n#', "<br>\n", $p)."</p>\n";
            }
        }

        return trim($out);
    }

    /**
     * Copy every wp-content/uploads file referenced in the HTML to the new public disk
     * and point the URLs at the new location.
     */
    public static function rewriteUploads(string $html, ?string $uploadsPath): string
    {
        if (! $uploadsPath || ! is_dir($uploadsPath)) {
            return $html;
        }

        $realBase = realpath($uploadsPath);

        return preg_replace_callback(
            '#(?:https?:)?//[^"\'\s>]+?/wp-content/uploads/([^"\'\s>?]+)|/[^"\'\s>]*?wp-content/uploads/([^"\'\s>?]+)#i',
            function (array $m) use ($realBase) {
                $relative = rawurldecode($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
                $source = realpath($realBase.DIRECTORY_SEPARATOR.$relative);

                // Path traversal guard + only real image / pdf files are copied
                if ($source === false || ! str_starts_with($source, $realBase) || ! is_file($source)) {
                    return $m[0];
                }
                $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
                if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
                    return $m[0];
                }
                if ($ext !== 'pdf' && @getimagesize($source) === false) {
                    return $m[0];
                }

                $target = 'media/'.md5($relative).'.'.$ext;
                $disk = Storage::disk('public');
                if (! $disk->exists($target)) {
                    $disk->put($target, file_get_contents($source));
                }

                return $disk->url($target);
            },
            $html
        ) ?? $html;
    }

    /**
     * Remove SEO-spam that hackers inject into WordPress pages: blocks hidden with
     * height:1px / display:none / off-screen positioning that contain links.
     */
    public static function removeHiddenSpam(string $html): string
    {
        $hidden = '(?:overflow\s*:\s*hidden\s*;\s*height\s*:\s*[01]px|height\s*:\s*[01]px\s*;\s*overflow\s*:\s*hidden|display\s*:\s*none|left\s*:\s*-\d{3,}px|font-size\s*:\s*0)';

        return preg_replace('#<(div|p|span)\b[^>]*style\s*=\s*["\'][^"\']*'.$hidden.'[^"\']*["\'][^>]*>.*?</\1>\s*#is', '', $html) ?? $html;
    }

    /** Full pipeline used for pages and portfolio descriptions. */
    public static function convert(?string $content, ?string $uploadsPath): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        $html = self::removeHiddenSpam(self::stripShortcodes(str_replace(["\r\n", "\r"], "\n", $content)));
        if (! preg_match('#<p[\s>]#i', $html)) {
            $html = self::autop($html);
        } else {
            // like wpautop(): single line breaks inside a paragraph are displayed as <br>
            $html = preg_replace_callback('#(<p\b[^>]*>)(.*?)(</p>)#is',
                fn ($m) => $m[1].preg_replace('#(?<!>)\n(?!<)#', "<br>\n", $m[2]).$m[3], $html) ?? $html;
        }

        return self::rewriteUploads($html, $uploadsPath);
    }
}
