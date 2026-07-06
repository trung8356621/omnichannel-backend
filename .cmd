php artisan queue:work --queue=media_generation,default --timeout=360


powershell -ExecutionPolicy Bypass -File D:\work\omnichannel-backend\compress_plugin.ps1

#CMD HOST
cd domains/seo.teamviahe.com/public_html
ln -s domains/seo.teamviahe.com/public_html/storage/app/public/uploads domains/seo.teamviahe.com/public_html/public/uploads/storage
mysql -u lzxzdusj_omi_seo_ai -p lzxzdusj_omi_seo_ai < seo.teamviahe.com/public_html/omi_seo_ai.sql
ztARSSpNQj5vpJ7MmHZj

#Để chạy queue
nohup php artisan queue:work > /dev/null 2>&1 &
pkill -f "queue:work"


# Reset migrations table trong CORE database
php artisan db:sql "TRUNCATE TABLE migrations;"

# Reset migrations table trong OMI_SEO_AI database
php artisan db:sql "TRUNCATE TABLE migrations;" --database=omi_seo_ai

# Reset migrations table trong WP_HEADLESS database  
php artisan db:sql "TRUNCATE TABLE migrations;" --database=wp_headless


# Chạy các file snapshot mới
php artisan migrate --path=database/migrations/2026_07_06_000001_create_core_tables.php

# Chạy snapshot cho SeoContentAi (theo đúng thứ tự connection)
php artisan migrate --path=app/Addons/SeoContentAi/database/migrations/2026_07_06_000002_create_seo_business_tables.php
php artisan migrate --path=app/Addons/SeoContentAi/database/migrations/2026_07_06_000003_create_seo_media_tables.php
php artisan migrate --path=app/Addons/SeoContentAi/database/migrations/2026_07_06_000004_create_seo_workflows_tables.php
php artisan migrate --path=app/Addons/SeoContentAi/database/migrations/2026_07_06_000005_create_seo_settings_tables.php

# Chạy snapshot cho WpHeadless
php artisan migrate --path=app/Addons/WpHeadless/database/migrations/2026_07_06_000006_create_wp_headless_tables.php

Ký tự: §