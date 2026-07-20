php artisan queue:work --queue=seo,media_generation,default --timeout=360





powershell -ExecutionPolicy Bypass -File D:\work\omnichannel-backend\compress_plugin.ps1



#CMD HOST

cd domains/seo.teamviahe.com/public_html

ln -s domains/seo.teamviahe.com/public_html/storage/app/public/uploads domains/seo.teamviahe.com/public_html/public/uploads/storage

mysql -u lzxzdusj_omi_seo_ai -p lzxzdusj_omi_seo_ai < seo.teamviahe.com/public_html/omi_seo_ai.sql

ztARSSpNQj5vpJ7MmHZj



#Để chạy queue (bắt buộc gồm queue seo cho rank check)

nohup php artisan queue:work --timeout=360 > storage/logs/queue-worker.log 2>&1 &

php artisan queue:work \

  --queue=seo,wordpress,media_generation,default \

  --stop-when-empty \

  --timeout=360 \

  --tries=3 \

  --memory=128



php artisan queue:work --queue=seo,media_generation,default --timeout=360







nohup php artisan queue:work --queue=seo,media_generation,default --timeout=360 > storage/logs/laravel-queue.log 2>&1 &



# =============================================================================

# FINAL RELEASE GATE — HOST ONLY (agent remote-first, không chạy local)

# Giữ maintenance mode đến khi verdict READY*

# =============================================================================



# --- 1. PRE-FLIGHT ---

php artisan down

php artisan optimize:clear

php artisan migrate:status

# Backup DB trước khi migrate (host tool / mysqldump)

# Kiểm tra: APP_ENV, APP_DEBUG=false, queue/cache/db, cron schedule:run, supervisor, timezone

php artisan migrate --force

php artisan optimize:clear



# --- 2. DATABASE INVARIANTS ---

php artisan content-project:diagnose --strict

php artisan automation:diagnose --strict

php artisan automation:health --json



# --- 3–10. E2E / SECURITY / QUEUE / SCHEDULER (test site riêng) ---

# A–J: Content Project create/run/retry/archive + Automation disabled/linear/graph/schedule/concurrency

# Import/export/template; tenant + permission; secret sanitization snapshots

php artisan queue:restart

php artisan queue:work \

  --queue=automation-critical,automation,automation-external,seo,wordpress,media_generation,default \

  --stop-when-empty \

  --tries=3 \

  --timeout=360

php artisan automation:recover-stale

php artisan schedule:list

php artisan schedule:run

# Gọi 2 lần: automation:dispatch-scheduled (kỳ vọng 1 occurrence)



# --- 11. FULL REGRESSION (một lượt cuối sau khi hết sửa) ---

php artisan optimize:clear

php artisan test

php artisan content-project:diagnose --strict

php artisan automation:diagnose --strict

php artisan automation:health



# --- 13. PRODUCTION CONFIG ---

php artisan config:cache

php artisan route:cache

php artisan view:cache

php artisan queue:restart

# Supervisor phải có: automation-critical,automation,automation-external (+ seo,wordpress,...)



# --- 14. RELEASE SWITCH ---

# Disable mọi external WP/sync/demo/imported/test rules

# Cancel pending/scheduled test executions (giữ history)

# Chỉ enable system/internal rule thật sự an toàn



# --- 15. MỞ USER (chỉ khi report = READY*) ---

# php artisan up

