<?php

return [
    // URL Next.js lấy theo từng site: wp_headless_sites.is_dev + headless_next_dev hoặc domain từ bảng sites.

    /**
     * Override URL Next.js cho webhook (receive / updated).
     * Khi set: Laravel gọi receive/updated tới URL này thay vì headless_next_dev (tránh lỗi "Failed to connect to localhost port 3000").
     * VD: http://127.0.0.1:3000 hoặc http://host.docker.internal:3000 khi Laravel chạy trong Docker.
     */
    'nextjs_webhook_url' => env('WP_HEADLESS_NEXTJS_WEBHOOK_URL', null),

    /**
     * Đường dẫn tuyệt đối tới thư mục public của Next.js (wp-headless).
     * Khi set: Laravel sau khi optimize CSS sẽ copy file sang đây để Next.js phục vụ local, không cần proxy.
     * VD: 'C:/work-2026/wp-headless/public' hoặc env('WP_HEADLESS_NEXTJS_PUBLIC_PATH').
     */
    'nextjs_public_path' => env('WP_HEADLESS_NEXTJS_PUBLIC_PATH', null),
];
