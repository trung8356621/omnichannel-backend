<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StylesOptimizedController extends Controller
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * POST /api/wp-headless/styles-optimized
     * Body: { "site_id": 1, "post_type": "page" }
     * Lấy template classes, lọc CSS, ghi file public (public/wp-headless/{site_id}/{post_type}-{chunk}.css), lưu path vào DB.
     * WordPress lấy trực tiếp qua URL trả về trong response.urls. Nếu > 100 KB thì tách nhiều file.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'site_id'   => 'required|integer|exists:sites,id',
            'post_type' => 'required|string|max:64',
        ]);

        $site = Site::find($request->input('site_id'));
        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
        }

        $result = $this->optimizer->optimize($site, $request->input('post_type'));

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Optimization failed',
            ], 422);
        }

        return response()->json($result);
    }
}
