<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessStyle;
use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trả về toàn bộ template HTML từ wp_headless_templates + URL CSS global (optimize 1 lần cho template global).
 * Next.js load global CSS qua URL (path), không còn inline từ content.
 */
class TemplatesController extends Controller
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * GET /api/wp-headless/templates?site_id=1
     * Response: { success, templates, template_relations, globalCssChunks: string[], fontUrls: string[] }
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
        $idToFileKey = [];
        foreach ($rows as $row) {
            $key = ($row->template_path !== null && trim((string) $row->template_path) !== '')
                ? $row->type . '-' . trim((string) $row->template_path)
                : $row->type;
            $idToFileKey[$row->id] = $key;
            $templates[$key] = [
                'template_path' => $row->template_path ?? '',
                'template'      => is_array($row->template) ? json_encode($row->template) : (string) ($row->template ?? ''),
                'bodyClass'     => is_array($row->body_class) ? $row->body_class : [],
                'ids'           => is_array($row->ids) ? $row->ids : [],
            ];
        }
        $templateRelations = [];
        foreach ($rows as $row) {
            // type = sidebar_* không tính parent_id (mỗi sidebar độc lập).
            if (str_starts_with((string) $row->type, 'sidebar_')) {
                continue;
            }
            if ($row->parent_id !== null && isset($idToFileKey[$row->parent_id])) {
                $templateRelations[$idToFileKey[$row->id]] = $idToFileKey[$row->parent_id];
            }
        }

        $hasGlobalTemplates = WpHeadlessTemplate::where('site_id', $siteId)
            ->where('global', true)
            ->exists();

        if ($hasGlobalTemplates && $this->optimizer->siteNeedsCssOptimize($site, 'global')) {
            $result = $this->optimizer->optimize($site, 'global');
            if (($result['success'] ?? false) !== true) {
                Log::warning('WpHeadless TemplatesController: global CSS optimize failed', [
                    'site_id' => $siteId,
                    'message' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $globalCssChunks = $this->optimizer->getOptimizedCssUrlPathsForPostType($site, 'global');

        $fontUrls = WpHeadlessStyle::where('site_id', $siteId)
            ->where('style_type', 'font')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderBy('sort_order')
            ->get()
            ->pluck('url')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'success'             => true,
            'templates'           => $templates,
            'template_relations'  => $templateRelations,
            'globalCssChunks'     => $globalCssChunks,
            'cssManifest'         => $this->optimizer->getCssManifest($site),
            'fontUrls'            => $fontUrls,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }
}
