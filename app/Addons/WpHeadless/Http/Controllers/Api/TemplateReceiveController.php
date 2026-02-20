<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Hook nhận template lẻ từ WordPress (header_block, footer_block Flatsome).
 * Lưu vào wp_headless_templates.
 *
 * Endpoint: POST /api/wp-headless/template-receive
 * Body: { site_url: string, templates: [{ type, template, body_class?, global? }] }
 * Auth: X-GraphQL-Secret = READ_TOKEN
 */
class TemplateReceiveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_url'  => 'required_without:site_id|string|url',
            'site_id'   => 'required_without:site_url|integer|exists:sites,id',
            'templates' => 'required|array',
            'templates.*.type'     => 'required|string|max:128',
            'templates.*.template' => 'required|string',
            'templates.*.body_class' => 'sometimes|array',
            'templates.*.global'   => 'sometimes|boolean',
        ]);

        $site = $this->resolveSite($request);
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Site not found.'], 404);
        }

        $token = $request->header('X-GraphQL-Secret') ?: $request->bearerToken();
        $siteService = $this->getWpHeadlessSiteService($site);
        if ($siteService === null) {
            return response()->json(['success' => false, 'message' => 'WP Headless not activated for this site.'], 403);
        }
        $readToken = $siteService->settings['READ_TOKEN'] ?? '';
        if ($token === '' || $token !== $readToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: invalid or missing token.'], 401);
        }

        $templates = $request->input('templates', []);
        $saved = 0;

        foreach ($templates as $item) {
            $type = (string) ($item['type'] ?? '');
            $html = trim((string) ($item['template'] ?? ''));
            if ($type === '' || $html === '') {
                continue;
            }

            $bodyClass = $item['body_class'] ?? [];
            $bodyClass = is_array($bodyClass) ? array_values($bodyClass) : [];
            $global = (bool) ($item['global'] ?? false);

            $parsed = $this->parseTemplateHtml($html);

            try {
                WpHeadlessTemplate::updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'type'    => $type,
                    ],
                    [
                        'parent_id'  => null,
                        'global'     => $global,
                        'template'   => $html,
                        'classes'    => $parsed['classes'],
                        'body_class' => $bodyClass,
                    ]
                );
                $saved++;
            } catch (\Throwable $e) {
                Log::warning('TemplateReceive: save failed', [
                    'site_id' => $site->id,
                    'type'    => $type,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'site_id' => $site->id,
            'saved'   => $saved,
        ]);
    }

    private function resolveSite(Request $request): ?Site
    {
        $siteId = $request->input('site_id');
        $siteUrl = $request->input('site_url');

        if ($siteId !== null) {
            return Site::find((int) $siteId);
        }
        if ($siteUrl !== null && $siteUrl !== '') {
            $domain = parse_url($siteUrl, PHP_URL_HOST);
            return $domain ? Site::where('domain', $domain)->first() : null;
        }
        return null;
    }

    private function getWpHeadlessSiteService(Site $site): ?SiteService
    {
        $service = Service::where('slug', 'wp-headless')->first();
        if ($service === null) {
            return null;
        }
        return SiteService::where('site_id', $site->id)
            ->where('service_id', $service->id)
            ->first();
    }

    private function parseTemplateHtml(string $html): array
    {
        $classes = [];
        if (preg_match_all('/\bclass\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $classAttr) {
                foreach (preg_split('/\s+/', trim($classAttr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $c = trim($c);
                    if ($c !== '') {
                        $classes[$c] = true;
                    }
                }
            }
        }
        $classes = array_keys($classes);
        sort($classes);
        return ['classes' => $classes];
    }
}
