<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Cli;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;

/**
 * Parse CLI-style composer input into skill key + form inputs.
 * UX boundary only — does not execute capabilities.
 */
final class AgentCliCommandParser
{
    /**
     * @param  list<string>  $keywordContext  1-indexed keyword list from last /keyword-suggest
     * @return array{
     *   ok: bool,
     *   command?: string,
     *   skill_key?: string|null,
     *   inputs?: array<string, mixed>,
     *   error?: string,
     *   is_meta?: bool
     * }
     */
    public function parse(string $raw, array $keywordContext = []): array
    {
        $text = trim($raw);
        if ($text === '' || ! str_starts_with($text, '/')) {
            return ['ok' => false, 'error' => 'not_cli'];
        }

        $commandToken = strtolower(explode(' ', $text, 2)[0] ?? '');
        $definition = AgentCliCommandCatalog::get($commandToken);
        if ($definition === null) {
            return ['ok' => false, 'error' => 'unknown_command'];
        }

        $rest = trim(substr($text, strlen($commandToken)));
        $parsed = $this->parseFlagsAndPositionals($rest, $definition);

        if (! ($parsed['ok'] ?? false)) {
            return $parsed;
        }

        $inputs = is_array($parsed['inputs'] ?? null) ? $parsed['inputs'] : [];

        // Meta commands (no skill_key).
        if (($definition['skill_key'] ?? null) === null) {
            return [
                'ok' => true,
                'command' => $definition['command'],
                'skill_key' => null,
                'inputs' => $inputs,
                'is_meta' => true,
            ];
        }

        // Keyword positional resolution.
        if (isset($inputs['keywords_tokens']) && is_string($inputs['keywords_tokens'])) {
            $resolved = $this->resolveKeywordTokens($inputs['keywords_tokens'], $keywordContext);
            if (! ($resolved['ok'] ?? false)) {
                return $resolved;
            }
            $inputs['items_text'] = implode("\n", $resolved['keywords']);
            unset($inputs['keywords_tokens']);
        }

        // Map project id to opaque ref when user passes numeric id.
        if (isset($inputs['project_ref']) && is_string($inputs['project_ref'])) {
            $inputs['project_ref'] = $this->resolveProjectRef($inputs['project_ref']);
        }

        return [
            'ok' => true,
            'command' => $definition['command'],
            'skill_key' => $definition['skill_key'],
            'inputs' => $inputs,
            'is_meta' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{ok:bool,inputs?:array<string,mixed>,error?:string}
     */
    private function parseFlagsAndPositionals(string $rest, array $definition): array
    {
        $inputs = [];
        $positional = '';

        if ($rest !== '') {
            $segments = [];
            if (preg_match_all('/(?:--[a-z0-9-]+|-[a-z])=(?:"[^"]*"|\'[^\']*\'|\S+)/i', $rest, $matches) === 1) {
                $segments = $matches[0];
            }

            $cursor = $rest;
            foreach ($segments as $segment) {
                $trimmed = trim($segment);
                if (preg_match('/^(--[a-z0-9-]+|-[a-z])=(.+)$/i', $trimmed, $fm) === 1) {
                    $flag = strtolower($fm[1]);
                    $value = $this->stripQuotes((string) $fm[2]);
                    $key = $this->mapFlagToKey($flag, $definition);
                    if ($key !== null) {
                        $inputs[$key] = $value;
                    }
                }
                $cursor = str_replace($segment, ' ', $cursor);
            }

            $positional = trim(preg_replace('/\s+/', ' ', $cursor) ?? '');
        }

        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                if ($positional !== '') {
                    $inputs[$arg['key']] = $positional;
                } elseif ((bool) ($arg['required'] ?? false)) {
                    return ['ok' => false, 'error' => 'missing_positional:'.$arg['key']];
                }

                continue;
            }

            $key = (string) ($arg['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ((bool) ($arg['required'] ?? false) && (! isset($inputs[$key]) || $inputs[$key] === '')) {
                return ['ok' => false, 'error' => 'missing_required:'.$key];
            }
        }

        return ['ok' => true, 'inputs' => $inputs];
    }

    /**
     * @param  list<string>  $keywordContext  1-indexed
     * @return array{ok:bool,keywords?:list<string>,error?:string}
     */
    public function resolveKeywordTokens(string $tokens, array $keywordContext): array
    {
        $parts = $this->splitKeywordTokenList($tokens);
        $out = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/^"(.*)"$/s', $part, $m) === 1 || preg_match("/^'(.*)'$/s", $part, $m) === 1) {
                $out[] = (string) $m[1];
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $part, $m) === 1) {
                if ($keywordContext === []) {
                    return [
                        'ok' => false,
                        'error' => 'no_keyword_context',
                    ];
                }

                $start = (int) $m[1];
                $end = (int) $m[2];
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                for ($i = $start; $i <= $end; $i++) {
                    $kw = $keywordContext[$i] ?? null;
                    if (! is_string($kw) || $kw === '') {
                        return [
                            'ok' => false,
                            'error' => 'keyword_index_missing:'.$i,
                        ];
                    }
                    $out[] = $kw;
                }
                continue;
            }

            if (ctype_digit($part)) {
                if ($keywordContext === []) {
                    return [
                        'ok' => false,
                        'error' => 'no_keyword_context',
                    ];
                }

                $idx = (int) $part;
                $kw = $keywordContext[$idx] ?? null;
                if (! is_string($kw) || $kw === '') {
                    return [
                        'ok' => false,
                        'error' => 'keyword_index_missing:'.$idx,
                    ];
                }
                $out[] = $kw;
                continue;
            }

            $out[] = $part;
        }

        if ($out === []) {
            return ['ok' => false, 'error' => 'empty_keywords'];
        }

        return ['ok' => true, 'keywords' => array_values($out)];
    }

    /**
     * @return list<string>
     */
    private function splitKeywordTokenList(string $raw): array
    {
        $parts = [];
        $current = '';
        $inQuote = null;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inQuote !== null) {
                if ($ch === $inQuote) {
                    $current .= $ch;
                    $inQuote = null;
                    continue;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ',') {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function mapFlagToKey(string $flag, array $definition): ?string
    {
        foreach ($definition['args'] as $arg) {
            foreach ($arg['flags'] as $f) {
                if (strtolower($f) === $flag) {
                    return (string) ($arg['key'] ?? null);
                }
            }
        }

        return null;
    }

    private function stripQuotes(string $value): string
    {
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function resolveProjectRef(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (ctype_digit($value)) {
            return ContentProjectPublicRef::project((int) $value);
        }

        return $value;
    }
}
