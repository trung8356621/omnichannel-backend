<?php
namespace App\Addons\WpHeadless\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Site;
use App\Models\Service;
use App\Models\SiteService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Str;

class WpHeadlessConnect extends Page
{
    // Chúng ta không cần View vì trang này chỉ xử lý logic rồi chuyển hướng
    protected static string $view = 'wp-headless::filament.pages.wp-connect';

    // Đường dẫn sẽ là /admin/wp-headless/connect
    protected static ?string $slug = 'wp-headless/connect';

    // Ẩn trang này khỏi menu bên trái
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Hàm mount() chạy ngay khi trang được nạp.
     * Vì đây là Filament Page, auth()->user() LUÔN LUÔN có dữ liệu tại đây.
     */
    public function mount(): void
    {
        // 1. Kiểm tra xác thực (Dành cho trường hợp SameSite Cookie bị chặn)
        if (!Auth::check()) {
            // Nếu người dùng bị văng ra login dù đã đăng nhập,
            // Laravel sẽ lưu URL hiện tại (kèm query string) vào 'intended'
            $this->redirect('admin/login?return_url=' . urlencode(request()->fullUrl()));
            return;
        }
        $admin_email = request()->query('admin_email');
        $siteUrl = request()->query('site_url');
        $returnUrl = request()->query('return_url');
        $user = Auth::user();
        //1.Check user
        if ($user->email != $admin_email) {
            Auth::logout(); // Clears the authentication information in the user's session
            $this->redirect('admin/login?email=' . $admin_email);
            return;
        }
        //#1.Check user


        //2.Site url

        if (!$siteUrl) {
            Notification::make()->title('Thiếu dữ liệu')->danger()->send();
            $this->redirect('/admin');
            return;
        }

        // 3. Logic xử lý kết nối
        $domain = parse_url($siteUrl, PHP_URL_HOST);

        $site = Site::updateOrCreate(
            ['domain' => $domain],
            [
                'user_id' => $user->id,
                'status' => 'active',
                'ssl' => str_starts_with($siteUrl, 'https'),
            ]
        );
        //#2.Site url

        $migration_token = 'mig_' . Str::random(32);
        $read_token = 'mig_' . Str::random(32);

        $service = Service::where('slug', 'wp-headless')->first();
        if ($service) {
            SiteService::updateOrCreate(
                ['site_id' => $site->id, 'service_id' => $service->id],
                [
                    'status' => 'inactive',
                    'settings' => [
                        'MIGRATION_TOKEN' => $migration_token,
                        'READ_TOKEN' => $read_token,
                        'connected_at' => now()->toDateTimeString(),

                    ]
                ]
            );
        }

        Notification::make()
            ->title('Kết nối thành công')
            ->success()
            ->send();

        // Chuyển về trang danh sách site
        $this->redirect($returnUrl . '&read_token=' . $read_token . '&write_token=' . $migration_token);

    }
}
