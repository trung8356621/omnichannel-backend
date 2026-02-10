<div class="flex items-center justify-center min-h-[400px]">
    <div class="text-center p-8 bg-slate-800/50 rounded-2xl border border-slate-700 shadow-xl max-w-md w-full">
        @if(auth()->check())
            <x-filament::loading-indicator class="w-12 h-12 mx-auto mb-4 text-primary-500" />
            <h2 class="text-xl font-bold text-white">Đang xác thực kết nối...</h2>
            <p class="text-slate-400 mt-2">Hệ thống đang đồng bộ dữ liệu với WordPress của bạn.</p>
        @else
            <div class="mb-4 text-amber-500">
                <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="text-xl font-bold text-white">Phiên đăng nhập hết hạn</h2>
            <p class="text-slate-400 mt-2 mb-6">Chúng tôi không thể nhận diện được tài khoản của bạn. Vui lòng đăng nhập lại để tiếp tục kết nối.</p>

            <div class="flex flex-col gap-3">
                <x-filament::button
                    tag="a"
                    href="{{ route('filament.admin.auth.login') }}"
                    color="primary"
                    class="w-full"
                >
                    Đăng nhập ngay
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    href="/admin"
                    color="gray"
                    variant="ghost"
                    class="w-full"
                >
                    Quay lại Dashboard
                </x-filament::button>
            </div>
        @endif
    </div>
</div>
