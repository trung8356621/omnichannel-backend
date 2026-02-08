<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-filament::section>
            <x-slot name="heading">Nhập từ khóa</x-slot>
            <div class="space-y-4">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" placeholder="Ví dụ: Cách chăm sóc cây cảnh..." />
                </x-filament::input.wrapper>

                <x-filament::button class="w-full">
                    Bắt đầu tạo bài viết
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Kết quả AI</x-slot>
            <div class="prose dark:prose-invert max-w-none text-slate-500 italic">
                Nội dung sẽ được hiển thị tại đây sau khi bạn bấm nút tạo...
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>