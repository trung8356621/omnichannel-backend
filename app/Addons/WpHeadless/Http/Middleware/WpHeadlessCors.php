<?php
namespace App\Addons\WpHeadless\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WpHeadlessCors
{
    /**
     * Middleware này cho phép WordPress gửi request sang Laravel
     * và chấp nhận việc truyền nhận Cookie/Session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin') ?: '*';

        // Xử lý Preflight Request (OPTIONS)
        if ($request->isMethod('OPTIONS')) {
            return response()->json('OK', 200, [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept',
                'Access-Control-Allow-Credentials' => 'true',
                'Vary' => 'Origin',
            ]);
        }

        $response = $next($request);

        // Thêm Header cho các response thực tế
        if (method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Vary', 'Origin');
        }

        return $response;
    }
}
