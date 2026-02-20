<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class SiteProxyController
{
    /**
     * Proxy request từ /site/{slug} và /site/{slug}/{path} tới Next.js.
     * Slug = host từ public_url (dấu chấm → gạch ngang), VD: myblog.com → myblog-com
     */
    public function __invoke(Request $request, string $slug, ?string $path = null): Response
    {
        $wpSite = WpHeadlessSite::all()->first(fn(WpHeadlessSite $s) => $s->public_url_slug === $slug);
        if ($wpSite === null) {
            abort(404, 'Site not found');
        }

        $baseUrl = $wpSite->getNextjsBaseUrl();
        if ($baseUrl === '') {
            abort(502, 'Next.js URL not configured for this site');
        }
        $requestPath = 'site/' . $slug . ($path !== null ? '/' . $path : '');
        $targetUrl = $baseUrl . '/' . $requestPath;

        $queryString = $request->getQueryString();
        if ($queryString !== null && $queryString !== '') {
            $targetUrl .= '?' . $queryString;
        }

        $forwardHeaders = $this->forwardHeaders($request);
        $method = $request->method();

        try {
            $client = Http::timeout(30)
                ->withHeaders($forwardHeaders)
                ->withOptions(['verify' => false]);

            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $client = $client->withBody($request->getContent(), $request->header('Content-Type', 'application/octet-stream'));
            }

            $response = $client->send($method, $targetUrl);

            $headers = $this->filterResponseHeaders($response->headers());
            return new Response(
                $response->body(),
                $response->status(),
                $headers
            );
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Proxy to headless app failed');
        }
    }

    private function forwardHeaders(Request $request): array
    {
        $allowed = [
            'accept',
            'accept-language',
            'content-type',
            'cookie',
            'x-requested-with',
            'x-csrf-token',
            'referer',
            'user-agent',
        ];
        $out = [];
        foreach ($request->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, $allowed, true)) {
                $out[$name] = $values;
            }
        }
        $out['X-Forwarded-For'] = $request->ip();
        $out['X-Forwarded-Host'] = $request->getHost();
        $out['X-Forwarded-Proto'] = $request->getScheme();
        return $out;
    }

    private function filterResponseHeaders(array $headers): array
    {
        $drop = ['transfer-encoding', 'connection', 'keep-alive'];
        $out = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), $drop, true)) {
                continue;
            }
            $out[$name] = is_array($values) ? $values[0] ?? '' : $values;
        }
        return $out;
    }
}
