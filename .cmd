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

