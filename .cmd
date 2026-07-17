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