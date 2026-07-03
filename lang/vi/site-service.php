<?php

declare(strict_types=1);

return [
    'seo_db_section_title' => 'Cấu hình Database SEO Content AI',
    'seo_db_section_description' => 'Site service chỉ chọn chế độ DB. Tạo kết nối cụ thể (host, user, password) tại SEO Database Connections.',
    'seo_db_config_mode' => 'Chế độ cấu hình DB',
    'seo_db_mode_auto' => 'Tự động (Docker Production)',
    'seo_db_mode_manual' => 'Thủ công (Hosting lẻ / Clone)',
    'seo_db_auto_note' => 'Ghi chú chế độ Tự động',
    'seo_db_auto_per_site' => 'Dùng MySQL credentials từ .env core, database: :database. Hệ thống tự đồng bộ bản ghi SEO Database Connection khi lưu.',
    'seo_db_auto_shared' => 'Dùng MySQL credentials từ .env core, database dùng chung: :database. Hệ thống tự đồng bộ bản ghi SEO Database Connection khi lưu.',
    'seo_db_manual_note' => 'Chế độ thủ công',
    'seo_db_manual_hint' => 'Chế độ thủ công: host, port, database, user và password cấu hình tại :link. Bạn có thể tạo connection sau khi lưu site service.',
    'seo_db_invalid_owner_context' => 'Phải chọn site hoặc owner hợp lệ để cấu hình database SEO.',
    'seo_db_invalid_manual_owner' => 'Owner không hợp lệ để dùng chế độ DB thủ công.',
    'seo_db_manual_create_connection_later' => 'Đã lưu site service ở chế độ thủ công. Tạo SEO Database Connection cho owner này khi sẵn sàng.',
    'seo_db_config_error_title' => 'Lỗi cấu hình database SEO',
    'seo_db_activated_title' => 'Đã kích hoạt SEO Content AI',
    'seo_db_ready_title' => 'Database SEO đã sẵn sàng',
    'seo_db_connected_no_migrations' => 'Database SEO đã kết nối. Không có migration còn thiếu.',
    'seo_db_connected_reconciled' => 'Database SEO đã kết nối. Đã đồng bộ :count migration CREATE có sẵn trên DB.',
    'seo_db_migrations_applied' => 'Đã áp dụng :count migration còn thiếu.',
    'seo_db_migrations_reconciled_suffix' => ' Đồng bộ thêm :count migration CREATE đã có bảng trên DB.',

    'bound_select_owner' => 'Chọn owner khi ràng buộc theo user.',
    'bound_owner_only' => 'Chỉ owner mới được ràng buộc trực tiếp theo user.',
    'bound_select_site' => 'Chọn site khi ràng buộc theo site.',

    'system_nav_group' => 'Hệ thống',
    'manage_services_nav' => 'Quản lý Service',
    'manage_services_title' => 'Hệ thống Addon & Dịch vụ',
    'service_not_found' => 'Không tìm thấy service',
    'cannot_activate_addon' => 'Không thể kích hoạt addon',
    'database_not_created' => 'Database chưa được tạo. Vui lòng tạo database ":name" (và chạy migration cho addon) trước khi kích hoạt.',
    'status_updated' => 'Cập nhật trạng thái thành công',
    'create_database_error' => 'Không lưu được vào database. Chạy migration core còn thiếu (cột bound_type trên site_services) rồi thử lại.',
];
