<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessStyleOptimized;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trả về toàn bộ template HTML từ wp_headless_templates + CSS inline global (optimize 1 lần cho template global).
 * Next.js lưu template + CSS thành file, gộp CSS thành inline embed.
 */
class TemplatesController extends Controller
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * GET /api/wp-headless/templates?site_id=1
     * Response: { success, templates: {...}, globalCssChunks: string[] }
     * Laravel tự chạy optimize CSS cho template global (global=1) nếu chưa có; gửi inline CSS kèm request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
        ]);

        $siteId = (int) $request->input('site_id');
        $site = Site::find($siteId);
        if (!$site) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $rows = WpHeadlessTemplate::where('site_id', $siteId)->get();
        $templates = [];
        foreach ($rows as $row) {
            $key = ($row->template_path !== null && trim((string) $row->template_path) !== '')
                ? $row->type . '-' . trim((string) $row->template_path)
                : $row->type;
            $templates[$key] = [
                'template_path' => $row->template_path ?? '',
                'template'      => $row->template ?? '',
                'bodyClass'     => is_array($row->body_class) ? $row->body_class : [],
            ];
        }

        $globalChunks = WpHeadlessStyleOptimized::where('site_id', $siteId)
            ->where('post_type', 'global')
            ->orderBy('chunk_index')
            ->get();

        $hasGlobalTemplates = WpHeadlessTemplate::where('site_id', $siteId)
            ->where('global', true)
            ->exists();
        $hasNoOptimizedContent = $globalChunks->isEmpty()
            || $globalChunks->every(fn ($r) => $r->content === null || $r->content === '');

        if ($hasGlobalTemplates && $hasNoOptimizedContent) {
            $result = $this->optimizer->optimize($site, 'global');
            if (($result['success'] ?? false) === true) {
                $globalChunks = WpHeadlessStyleOptimized::where('site_id', $siteId)
                    ->where('post_type', 'global')
                    ->orderBy('chunk_index')
                    ->get();
            } else {
                Log::warning('WpHeadless TemplatesController: global CSS optimize failed', [
                    'site_id' => $siteId,
                    'message' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $globalCssChunks = $globalChunks->map(fn ($r) => (string) ($r->content ?? ''))->filter()->values()->all();

        return response()->json([
            'success'         => true,
            'templates'       => $templates,
            'globalCssChunks' => $globalCssChunks,
        ]);
    }
}
