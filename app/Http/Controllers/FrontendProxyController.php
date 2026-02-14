<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FrontendProject;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Proxy request /frontend/{router}/{path?} tới Next.js/React (theo FrontendProject.port).
 * Chỉ proxy khi FrontendProject.proxy_auto = true.
 */
class FrontendProxyController
{
    public function __invoke(Request $request, string $router, ?string $path = null): Response
    {
        $project = FrontendProject::where('router', $router)->where('proxy_auto', true)->first();
        if ($project === null) {
            abort(404, 'Frontend project not found or proxy disabled');
        }

        $port = (int) ($project->port ?? 3000);
        $baseUrl = 'http://127.0.0.1:' . $port;
        $targetUrl = rtrim($baseUrl, '/') . '/' . ltrim($path ?? '', '/');

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
                $client = $client->withBody(
                    $request->getContent(),
                    $request->header('Content-Type', 'application/octet-stream')
                );
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
            abort(502, 'Proxy to frontend app failed');
        }
    }

    private function forwardHeaders(Request $request): array
    {
        $allowed = [
            'accept', 'accept-language', 'content-type', 'cookie',
            'x-requested-with', 'x-csrf-token', 'referer', 'user-agent',
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
