<?php

namespace App\Providers\Filament;

use Omnichannel\Addons\Agent\Filament\Pages\AutomationFlowsPage;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\AutomationOperationsDashboard;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationSettings;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationWorkflowBuilder;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use App\Filament\Pages\Auth\CustomLogin;
use App\Filament\Pages\ManageServices;
use App\Http\Middleware\Filament\RedirectStaffFromAdminPanel;
use App\Http\Middleware\Filament\RestrictAdminAutomationOnlyUsers;
use App\Models\Service;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use File;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Schema;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                ManageServices::class,
                AutomationFlowsPage::class,
                AutomationOperationsDashboard::class,
                AutomationSettings::class,
                AutomationWorkflowBuilder::class,
            ])
            ->resources([
                AutomationRuleResource::class,
                AutomationExecutionResource::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                RedirectStaffFromAdminPanel::class,
                Authenticate::class,
                RestrictAdminAutomationOnlyUsers::class,
            ]);

        return $this->discover_addons($panel);
    }

    // 2. LOGIC TỰ ĐỘNG KHÁM PHÁ ADDONS (DYNAMIC DISCOVERY)
    private function discover_addons(Panel $panel)
    {
        // 2. LOGIC TỰ ĐỘNG KHÁM PHÁ ADDONS (DYNAMIC DISCOVERY)
        try {
            // Chỉ quét nếu bảng services đã tồn tại (tránh lỗi khi migrate)
            if (Schema::hasTable('services')) {
                // Chỉ lấy những Addon đang ở trạng thái kích hoạt (Active)
                $activeServices = Service::where('is_active', true)->get();

                foreach ($activeServices as $service) {
                    // Chuyển slug thành tên thư mục PascalCase (ví dụ: wp-headless -> WpHeadless)
                    $folderName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $service->slug)));
                    $addonPath = app_path("Addons/{$folderName}");
                    if (File::isDirectory($addonPath)) {

                        /**
                         * TỰ ĐỘNG NẠP VIEW:
                         * Lệnh này thay thế cho việc khai báo thủ công ở từng Provider.
                         * Namespace sẽ là chính slug của service (vd: @wp-headless).
                         */
                        $viewsPath = "{$addonPath}/resources/views";
                        if (File::isDirectory($viewsPath)) {
                            $this->loadViewsFrom($viewsPath, $service->slug);
                        }

                        // SEO Content AI panel riêng (/seo). Automation UI gắn vào /admin tại đây
                        // (discoverPages addon SEO bị skip — không pollute Articles/Media vào admin).
                        if ($service->slug === 'seo-content-ai') {
                            $panel
                                ->pages([
                                    AutomationFlowsPage::class,
                                    AutomationOperationsDashboard::class,
                                    AutomationSettings::class,
                                    AutomationWorkflowBuilder::class,
                                ])
                                ->resources([
                                    AutomationRuleResource::class,
                                    AutomationExecutionResource::class,
                                ]);

                            continue;
                        }

                        // Tự động quét và nạp các Pages của Addon
                        $pagesPath = "{$addonPath}/Filament/Pages";
                        if (File::isDirectory($pagesPath)) {
                            $panel->discoverPages(
                                in: $pagesPath,
                                for: "App\\Addons\\{$folderName}\\Filament\\Pages"
                            );
                        }

                        // Tự động quét và nạp các Resources của Addon
                        $resourcesPath = "{$addonPath}/Filament/Resources";
                        if (File::isDirectory($resourcesPath)) {
                            $panel->discoverResources(
                                in: $resourcesPath,
                                for: "App\\Addons\\{$folderName}\\Filament\\Resources"
                            );
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Ghi log lỗi nếu cần thiết nhưng không làm sập Panel
            report($e);
        }

        return $panel;
    }
}
