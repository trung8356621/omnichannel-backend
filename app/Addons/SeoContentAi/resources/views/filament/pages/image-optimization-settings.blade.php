<x-filament-panels::page>
    @vite('app/Addons/SeoContentAi/resources/css/image-optimization-settings.css')

    <div class="seo-image-opt">
        <div class="seo-image-opt__toolbar">
            <div class="seo-image-opt__toolbar-row">
                <label class="seo-image-opt__label" for="seo-image-opt-site">Áp dụng cho Website:</label>
                <select id="seo-image-opt-site" wire:model.live="siteId" class="seo-image-opt__select">
                    <option value="">-- Mặc định hệ thống (Global) --</option>
                    @foreach ($this->sites as $site)
                        <option value="{{ $site->id }}">{{ $site->domain }}</option>
                    @endforeach
                </select>
            </div>
            <p class="seo-image-opt__hint">Cấu hình riêng sẽ ghi đè lên cấu hình chung của hệ thống</p>
        </div>

        <div class="seo-image-opt__grid">
            <section class="seo-image-opt__card">
                <h3 class="seo-image-opt__card-title">Nén &amp; Chuyển đổi định dạng</h3>
                <hr class="seo-image-opt__divider" />

                <label class="seo-image-opt__check">
                    <input type="checkbox" wire:model="data.auto_convert_webp" />
                    <span>
                        <strong>Tự động chuyển đổi sang WebP</strong>
                        <small>Được khuyên dùng để tăng tốc độ tải trang cho Google PageSpeed</small>
                    </span>
                </label>

                <div class="seo-image-opt__range-wrap">
                    <label class="seo-image-opt__range-label">
                        Chất lượng nén hình ảnh (Quality: {{ $data['quality'] ?? 80 }}%)
                    </label>
                    <input type="range" min="10" max="100" wire:model.live="data.quality" class="seo-image-opt__range" />
                    <div class="seo-image-opt__range-hints">
                        <span>Tối ưu nhất (10%)</span>
                        <span>Cân bằng (80%)</span>
                        <span>Gốc (100%)</span>
                    </div>
                </div>
            </section>

            <section class="seo-image-opt__card">
                <h3 class="seo-image-opt__card-title">Giới hạn Kích thước (Resize)</h3>
                <hr class="seo-image-opt__divider" />

                <label class="seo-image-opt__check">
                    <input type="checkbox" wire:model.live="data.limit_dimensions" />
                    <span>
                        <strong>Bật giới hạn kích thước</strong>
                        <small>Tự động thu nhỏ ảnh vượt quá độ rộng chuẩn để tránh tốn dung lượng ổ đĩa</small>
                    </span>
                </label>

                @if ($data['limit_dimensions'] ?? false)
                    <div class="seo-image-opt__dims">
                        <div>
                            <label class="seo-image-opt__field-label">Chiều rộng tối đa (px)</label>
                            <input type="number" min="100" wire:model="data.max_width" class="seo-image-opt__input" />
                        </div>
                        <div>
                            <label class="seo-image-opt__field-label">Chiều cao tối đa (px)</label>
                            <input type="number" min="100" wire:model="data.max_height" class="seo-image-opt__input" />
                        </div>
                    </div>
                @endif
            </section>

            <section class="seo-image-opt__card seo-image-opt__card--wide">
                <h3 class="seo-image-opt__card-title">Chuẩn hóa SEO Tên File &amp; Thẻ ALT</h3>
                <hr class="seo-image-opt__divider" />

                <div class="seo-image-opt__seo-grid">
                    <div class="seo-image-opt__seo-checks">
                        <label class="seo-image-opt__check">
                            <input type="checkbox" wire:model="data.clean_filename" />
                            <span>
                                <strong>Tự động dọn dẹp tên File</strong>
                                <small>Xóa dấu tiếng Việt, ký tự đặc biệt, thay khoảng trắng thành dấu gạch ngang</small>
                            </span>
                        </label>

                        <label class="seo-image-opt__check">
                            <input type="checkbox" wire:model.live="data.auto_alt_tag" />
                            <span>
                                <strong>Tự động tạo thẻ ALT</strong>
                                <small>Tự sinh văn bản thay thế (alt) chuẩn SEO cho hình ảnh khi upload từ bài viết</small>
                            </span>
                        </label>
                    </div>

                    @if ($data['auto_alt_tag'] ?? false)
                        <div>
                            <label class="seo-image-opt__field-label">Mẫu thiết lập ALT Tag</label>
                            <input
                                type="text"
                                wire:model="data.alt_tag_pattern"
                                class="seo-image-opt__input"
                                placeholder="{post_title} - {focus_keyword}"
                            />
                            <p class="seo-image-opt__pattern-hint">
                                Biến động: <code>{post_title}</code> — tiêu đề bài viết,
                                <code>{focus_keyword}</code> — từ khóa chính SEO.
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="seo-image-opt__actions">
            <x-filament::button wire:click="save" wire:loading.attr="disabled">
                Lưu cấu hình
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
