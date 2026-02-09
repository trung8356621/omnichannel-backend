<?php
namespace App\Addons\WpHeadless\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WpHeadlessCors
{
    /**
     * Middleware này đảm bảo:
     * 1. Thêm các Header CORS cần thiết để WordPress có thể "bắt tay".
     * 2. Cấu hình Cookie để trình duyệt không chặn Session khi chuyển hướng chéo trang.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Cho phép WordPress (hoặc mọi nguồn) truy cập
        $origin = $request->header('Origin') ?: '*';

        // Nếu là request OPTIONS (Preflight), trả về 200 ngay lập tức
        if ($request->isMethod('OPTIONS')) {
            return response()->json('OK', 200, [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
                'Access-Control-Allow-Credentials' => 'true',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        // Kiểm tra xem $response có phương thức headers không (tránh lỗi với một số loại response đặc biệt)
        if (method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            $response->header('Access-Control-Allow-Credentials', 'true');
        } elseif (isset($response->headers)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
