<?php

namespace App\Addons\WpHeadless\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Service;
use App\Models\SiteService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class HeadlessController extends Controller
{
    /**
     * Tiếp nhận yêu cầu kết nối từ WordPress Plugin.
     * WordPress sẽ redirect người dùng sang link này kèm thông tin site.
     */
    public function connect(Request $request)
    {
        if (!auth()->check()) {
            // Nếu chưa đăng nhập, chuyển hướng sang login và nhớ link hiện tại để quay lại sau khi login
            return redirect()->guest(route('filament.admin.auth.login'));
        }
        // Lúc này user chắc chắn không null vì đã đi qua Auth Middleware của Filament
        $user = auth()->check();
        dd($user);
        $siteUrl = $request->query('url');
        $siteName = $request->query('name');
        $appToken = $request->query('token');

        if (!$siteUrl) {
            Notification::make()->title('Lỗi kết nối')->body('Dữ liệu từ WordPress không hợp lệ.')->danger()->send();
            return redirect()->to('/admin');
        }

        // Bóc tách domain
        $domain = parse_url($siteUrl, PHP_URL_HOST);

        // 1. Tạo/Cập nhật Website
        $site = Site::updateOrCreate(
            ['domain' => $domain],
            [
                'user_id' => $user->id,
                'url' => $siteUrl,
                'status' => 'active',
                'ssl' => str_starts_with($siteUrl, 'https'),
            ]
        );

        // 2. Kích hoạt Addon wp-headless cho Website này
        $service = Service::where('slug', 'wp-headless')->first();

        if ($service) {
            SiteService::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'service_id' => $service->id,
                ],
                [
                    'status' => 'active',
                    'settings' => [
                        'wp_token' => $appToken,
                        'wp_admin_name' => $siteName,
                        'connected_at' => now()->toDateTimeString(),
                    ]
                ]
            );
        }

        Notification::make()
            ->title('Kết nối thành công!')
            ->body("Website {$domain} đã được tích hợp vào hệ thống.")
            ->success()
            ->send();

        // Chuyển hướng về Dashboard của Addon (Trang này đã nhận site_id)
        return redirect()->to("/admin/wp-headless/manage?site_id={$site->id}");
    }
}
