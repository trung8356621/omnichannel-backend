<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Http\Controllers\Api;

use App\Addons\WpHeadless\Services\WpHeadlessStylesOptimizerService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptimizedCssByClassesController extends Controller
{
    public function __construct(
        private WpHeadlessStylesOptimizerService $optimizer
    ) {}

    /**
     * POST /api/wp-headless/optimized-css-by-classes
     * Body: { site_id, post_type, uri, classes[] }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'post_type' => 'required|string|max:64',
            'uri' => 'required|string|max:2048',
            'classes' => 'required|array|min:1',
            'classes.*' => 'required|string|max:255',
        ]);

        $site = Site::find((int) $request->input('site_id'));
        if (!$site) {
            return response()->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $result = $this->optimizer->optimizeByClasses(
            $site,
            (string) $request->input('post_type'),
            (array) $request->input('classes', []),
            (string) $request->input('uri')
        );

        $status = ($result['success'] ?? false) ? 200 : 422;
        return response()->json($result, $status);
    }
}

