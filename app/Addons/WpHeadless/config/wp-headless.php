<?php

return [
    // URL Next.js lấy theo từng site: wp_headless_sites.is_dev + headless_next_dev hoặc domain từ bảng sites.

    /**
     * Đường dẫn tuyệt đối tới thư mục public của Next.js (wp-headless).
     * Khi set: Laravel sau khi optimize CSS sẽ copy file sang đây để Next.js phục vụ local, không cần proxy.
     * VD: 'C:/work-2026/wp-headless/public' hoặc env('WP_HEADLESS_NEXTJS_PUBLIC_PATH').
     */
    'nextjs_public_path' => env('WP_HEADLESS_NEXTJS_PUBLIC_PATH', null),
];
