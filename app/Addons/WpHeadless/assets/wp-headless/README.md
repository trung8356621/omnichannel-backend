# wp-headless (Next.js)

Project Next.js của addon WpHeadless, nhận request qua proxy từ Laravel `/site/{slug}`.

## Chạy dev

```bash
cd app/Addons/WpHeadless/wp-headless
npm install
npm run dev
```

Mặc định chạy tại `http://localhost:3000`. Cấu hình `WP_HEADLESS_NEXTJS_URL` trong `.env` của Laravel nếu cần.

## Route

- `/site/[slug]` – trang chính theo slug site
- `/site/[slug]/[...path]` – path con (post, page, category...)
