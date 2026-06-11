<div class="article-editor-shortcuts-panel">
    <p class="article-editor-shortcuts-title">Phím tắt</p>
    <div class="article-editor-shortcuts-groups">
        <section class="article-editor-shortcuts-group">
            <h5 class="article-editor-shortcuts-group-label">Bài viết</h5>
            <ul class="article-editor-shortcuts-list">
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>S</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Lưu bài viết</span>
                </li>
                @if (! \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager())
                    <li>
                        <span class="article-editor-shortcuts-keys">
                            <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>S</kbd>
                        </span>
                        <span class="article-editor-shortcuts-desc">Đồng bộ WordPress</span>
                    </li>
                @endif
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>P</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Xem trước bài viết</span>
                </li>
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>E</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Mở / ẩn mô tả SEO</span>
                </li>
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>A</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Phân tích SEO</span>
                </li>
            </ul>
        </section>
        <section class="article-editor-shortcuts-group">
            <h5 class="article-editor-shortcuts-group-label">Chỉnh sửa nội dung</h5>
            <ul class="article-editor-shortcuts-list">
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Z</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Hoàn tác</span>
                </li>
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Y</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Làm lại</span>
                </li>
                <li>
                    <span class="article-editor-shortcuts-keys">
                        <kbd>Ctrl</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Shift</kbd><span class="article-editor-shortcuts-plus">+</span><kbd>Z</kbd>
                    </span>
                    <span class="article-editor-shortcuts-desc">Làm lại (thay thế)</span>
                </li>
            </ul>
        </section>
    </div>
</div>
