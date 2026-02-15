<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Models\WpHeadlessTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trả về toàn bộ template HTML từ wp_headless_templates để Next.js lưu file và embed (header / footer / post_type).
 */
class TemplatesController extends Controller
{
    /**
     * GET /api/wp-headless/templates?site_id=1
     * Response: { "header": "<html>...", "footer": "...", "post": "...", "page": "...", ... }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
        ]);

        $siteId = (int) $request->input('site_id');
        $rows = WpHeadlessTemplate::where('site_id', $siteId)->get();

        $templates = [];
        foreach ($rows as $row) {
            $templates[$row->type] = [
                'template'  => $row->template ?? '',
                'bodyClass' => is_array($row->body_class) ? $row->body_class : [],
            ];
        }

        return response()->json([
            'success'   => true,
            'templates' => $templates,
        ]);
    }
}
