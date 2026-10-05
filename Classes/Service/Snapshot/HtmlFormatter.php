<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

final readonly class HtmlFormatter
{
    public function format(string $html): string
    {
        // Quoted > characters, comments and raw-text content are not tag boundaries.
        preg_match_all('~<!--[\\s\\S]*?-->|<(script|style|pre|textarea)\\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\\s\\S]*?</\\1\\s*>|</?[a-zA-Z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>|<![^>]*>|[^<]+|<~i', $html, $matches);
        $output = '';
        foreach ($matches[0] as $token) {
            if (preg_match('~^</?[a-zA-Z]|^<!~', $token)) {
                $output .= $output === '' ? '' : "\n";
                if (
                    strlen($token) > 80 && preg_match('~^<([a-zA-Z][\\w:-]*)(\\s(?:[^>\"\']|\"[^\"]*\"|\'[^\']*\')*?)(/?)>~', $token, $tag)
                    && mb_strlen($tag[0]) > 80
                ) {
                    preg_match_all('~[^\\s=]+(?:\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s]+))?~', trim($tag[2]), $attributes);
                    $token = '<' . $tag[1] . "\n  " . implode("\n  ", $attributes[0]) . "\n" . $tag[3] . '>' . substr($token, strlen($tag[0]));
                }
            }

            $output .= $token;
        }

        return $output . "\n";
    }
}
