# Changelog — WebP blank / fallback sync

## 2026-07-15 (d) — Sync không bị chặn khi WebP fail

### Vấn đề
`prepareWordPressUploadFile` return `null` khi WebP fail / >100KB / validateSource reject → WordPress sync bị chặn dù ảnh gốc còn hợp lệ.

### Fix
- WebP chỉ ưu tiên; fail → fallback original (fresh), **tiếp tục sync**.
- Chỉ `null` khi gốc thiếu/undecodeable.
- >100KB sau max attempts → log warning, dùng bản nhỏ nhất / gốc, **vẫn sync**.
- Log: `SEO_MEDIA_FALLBACK_FROM_ORIGINAL`, `SEO_MEDIA_FALLBACK_COMPRESSED`, `SEO_MEDIA_FALLBACK_OVER_TARGET_SIZE`, `SEO_MEDIA_SYNC_CONTINUED_WITH_FALLBACK`.

## 2026-07-15 (c) — Root cause Imagick ACTIVATE

`ALPHACHANNEL_ACTIVATE` trước WebP write → alpha=0. Đã bỏ ACTIVATE + signature validator.

## 2026-07-15 (b)/(a)

Triệu chứng flatten/blacklist — superseded.
