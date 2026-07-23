/**
 * Central Help topic registry for Article Editor.
 * Keep shape stable so content can later move to DB/API without rewriting the modal.
 *
 * @typedef {{ title?: string, duration?: string, thumbnail?: string|null, url?: string, longUrl?: string|null }} ArticleEditorHelpVideo
 * @typedef {{ type: 'widget'|'module'|'scroll', id: string }} ArticleEditorHelpTarget
 * @typedef {{
 *   key: string,
 *   title: string,
 *   summary: string,
 *   steps: string[],
 *   video: ArticleEditorHelpVideo|null,
 *   target: ArticleEditorHelpTarget|null,
 * }} ArticleEditorHelpTopic
 */

/** @type {ArticleEditorHelpTopic[]} */
export const ARTICLE_EDITOR_HELP_TOPICS = [
    {
        key: 'article-editor.overview',
        title: 'Tổng quan Article Editor',
        summary: 'Trang chỉnh sửa bài viết với sticky action header, dàn ý, Google Preview và các module SEO/Links/FAQ.',
        steps: [
            'Dùng sticky header để Save, Sync WP, Preview, Approve.',
            'Cột trái: Google Preview + Outline.',
            'Cột phải: SEO Assistant và Publishing.',
            'Nút Help luôn mở cùng một modal hướng dẫn toàn cục.',
        ],
        video: null,
        target: null,
    },
    {
        key: 'article-editor.save-draft',
        title: 'Lưu bài và bản nháp local',
        summary: 'Save ghi server; gõ nội dung tạo draft local trong trình duyệt.',
        steps: [
            'Bấm Save article hoặc Ctrl+S để lưu bài.',
            'Trạng thái Saving / Draft saved locally / Conflict hiện trên sticky header.',
            'Draft local khôi phục khi mở lại nếu khác bản server.',
            'Conflict 409: giữ draft, không reload tự động.',
        ],
        video: null,
        target: null,
    },
    {
        key: 'article-editor.sync-wp',
        title: 'Đồng bộ WordPress',
        summary: 'Sync WP đẩy nội dung sang WordPress qua automation queue.',
        steps: [
            'Bấm Sync WP hoặc Ctrl+Shift+S.',
            'Chờ overlay hoàn tất — không đóng tab khi đang sync.',
            'Restore từ More menu để kéo bản WP về (ghi đè local).',
        ],
        video: null,
        target: null,
    },
    {
        key: 'article-editor.outline',
        title: 'Outline / Dàn ý',
        summary: 'Quản lý heading H2–H4, nhảy tới section, thêm/đổi thứ tự.',
        steps: [
            'Thêm section từ Outline.',
            'Đổi thứ tự heading bằng kéo hoặc nút move.',
            'Bấm heading để cuộn tới nội dung tương ứng.',
            'Dò trùng lặp heading trước khi xuất bản.',
        ],
        video: null,
        target: { type: 'widget', id: 'outline' },
    },
    {
        key: 'article-editor.google-preview',
        title: 'Google Preview',
        summary: 'Xem trước title/meta description dạng SERP và chỉnh SEO fields.',
        steps: [
            'Chỉnh SEO title / meta description trên preview.',
            'Theo dõi độ dài và keyword highlight.',
            'Lưu SEO fields trước khi Sync WP.',
        ],
        video: null,
        target: { type: 'scroll', id: 'google-preview' },
    },
    {
        key: 'article-editor.seo-assistant',
        title: 'SEO Assistant',
        summary: 'Phân tích SEO, gợi ý sửa, mở module liên quan.',
        steps: [
            'Mở tab SEO trên sidebar phải.',
            'Chạy Analyze sau khi nội dung thay đổi.',
            'Bấm violation để nhảy tới vùng cần sửa.',
        ],
        video: null,
        target: { type: 'module', id: 'seo' },
    },
    {
        key: 'article-editor.featured-image',
        title: 'Featured Image',
        summary: 'Chọn / tạo ảnh đại diện cho bài viết.',
        steps: [
            'Mở module Images.',
            'Chọn ảnh từ media library hoặc generate.',
            'Kiểm tra alt text trước khi Sync.',
        ],
        video: null,
        target: { type: 'module', id: 'images' },
    },
    {
        key: 'article-editor.images',
        title: 'Images / Album',
        summary: 'Ảnh trong bài, album sản phẩm, fix slug hàng loạt.',
        steps: [
            'Mở Images để xem danh sách ảnh trong bài.',
            'Dùng Quick fix slug khi cần chuẩn hóa URL.',
            'Album sản phẩm chỉ hiện với post type hỗ trợ gallery.',
        ],
        video: null,
        target: { type: 'module', id: 'images' },
    },
    {
        key: 'article-editor.reviews',
        title: 'Reviews',
        summary: 'Đánh giá sản phẩm / virtual reviews gắn với bài.',
        steps: [
            'Mở module Reviews.',
            'Generate hoặc refresh reviews khi cần.',
            'Kiểm tra trạng thái sync WP trước khi xuất bản.',
        ],
        video: null,
        target: { type: 'module', id: 'reviews' },
    },
    {
        key: 'article-editor.links',
        title: 'Links',
        summary: 'Internal / external links và gợi ý liên kết nội bộ.',
        steps: [
            'Mở module Links.',
            'Thêm internal link từ gợi ý hoặc search.',
            'Sửa / gỡ link trực tiếp trên bubble trong editor.',
        ],
        video: null,
        target: { type: 'module', id: 'links' },
    },
    {
        key: 'article-editor.faq',
        title: 'FAQ',
        summary: 'Câu hỏi FAQ gắn shortcode [omi_faq].',
        steps: [
            'Mở module FAQ.',
            'Thêm / sửa câu hỏi và câu trả lời.',
            'Đảm bảo bài chỉ có một shortcode FAQ.',
        ],
        video: null,
        target: { type: 'module', id: 'faq' },
    },
    {
        key: 'article-editor.cta',
        title: 'CTA',
        summary: 'Call-to-action blocks trong bài.',
        steps: [
            'Mở module CTA.',
            'Chọn hoặc chỉnh CTA phù hợp nội dung.',
            'Preview vị trí CTA trước khi Save.',
        ],
        video: null,
        target: { type: 'module', id: 'cta' },
    },
    {
        key: 'article-editor.publishing',
        title: 'Publishing',
        summary: 'Trạng thái, lịch đăng, categories WordPress.',
        steps: [
            'Mở Publishing trên sidebar.',
            'Chọn status / schedule / categories.',
            'Save rồi Sync WP để áp dụng lên WordPress.',
        ],
        video: null,
        target: { type: 'module', id: 'publishing' },
    },
    {
        key: 'article-editor.troubleshooting',
        title: 'Xử lý lỗi thường gặp',
        summary: 'Conflict save, sync treo, SEO stale, draft local.',
        steps: [
            'Save conflict: giữ draft, so sánh rồi lưu lại.',
            'Sync overlay quá lâu: kiểm tra queue / Retry.',
            'SEO stale: bấm Analyze lại.',
            'Draft local sai: chọn Keep server trong modal restore.',
        ],
        video: null,
        target: null,
    },
];

/**
 * @param {string|null|undefined} key
 * @returns {ArticleEditorHelpTopic|null}
 */
export function findArticleEditorHelpTopic(key) {
    const normalized = String(key ?? '').trim();
    if (normalized === '') {
        return null;
    }

    return ARTICLE_EDITOR_HELP_TOPICS.find((topic) => topic.key === normalized) ?? null;
}

export const ARTICLE_EDITOR_HELP_OPEN_EVENT = 'article-editor:help-open';
export const ARTICLE_EDITOR_SAVE_STATUS_EVENT = 'article-editor:save-status';
