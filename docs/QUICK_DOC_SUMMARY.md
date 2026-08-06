# Quick Documentation Summary

## 1. Mục tiêu của docs hiện tại

Docs trong repo này đang được tổ chức như một hệ thống tài liệu chuẩn cho backend Omnichannel, tập trung vào các phần:

- kiến trúc hệ thống
- module nghiệp vụ
- contract/API
- vận hành và kiểm thử
- audit hiện tại
- archive lịch sử

## 2. Cấu trúc chính

### A. Canonical docs (nguồn truth)

- [docs/README.md](README.md): index chính, quy định ưu tiên và nơi bắt đầu khi tìm docs.
- [docs/architecture](architecture): mô tả kiến trúc, ranh giới dữ liệu/runtime, và các quyết định thiết kế quan trọng.
- [docs/modules](modules): bản mô tả từng module nghiệp vụ như Content Projects, Publishing, Article Editor, Site Sync, Prompt/AI, Media, Extension SDK.
- [docs/contracts](contracts): các contract công khai và invariant quan trọng cho Agent/MCP, API/Auth, Queue/Scheduler, Extension Registry.
- [docs/operations](operations): vận hành, deploy, scheduler, testing, troubleshooting.
- [docs/audits](audits): các audit đang active.

### B. Archive

- [docs/archive](archive): chỉ dùng để tra lịch sử, không dùng làm source of truth.

## 3. Các chủ đề trọng tâm hiện nay

### Prompt và AI

- Quyền sở hữu prompt được tách rõ khỏi hook engine.
- Prompt bindings, task-owned prompt, provider resolver, và workflow artifacts đều có phân tầng rõ ràng.
- Không dùng dual-write; AI output chỉ được áp dụng qua workflow/action/domain layer.
- Hook chạy theo schema, fail-closed, không tự động fallback sai.

### Article Editor

- Editor là một hệ thống riêng gồm Livewire shell + React TipTap editor.
- Có quy định rõ về session lock, document version, media snapshot ownership, SEO analysis ownership, và việc lưu nội dung.
- Đồng bộ WordPress được tách riêng khỏi save local, tránh overwrite sai.

### Publishing và Site Sync

- Publishing schedule do Laravel sở hữu.
- WordPress chỉ nhận kết quả sync/publish, không phải nguồn truth cho lịch trình.
- Site Sync và bridge contract phải được xem xét cả ở backend và plugin.

## 4. Nguyên tắc sử dụng docs

- Khi làm việc với feature mới, ưu tiên đọc canonical docs trước.
- Archive chỉ dùng để tham khảo lịch sử, không dùng làm căn cứ cho decision mới.
- Nếu có thay đổi contract/API/auth/site-sync/publishing/media, phải kiểm tra cả backend và plugin repo.

## 5. Tóm tắt ngắn gọn

Docs hiện tại đang tập trung vào ba trục chính:

1. Kiến trúc và boundary rõ ràng.
2. Module nghiệp vụ được tách ra thành tài liệu riêng.
3. Contract và operational docs đảm bảo việc vận hành, kiểm thử và tích hợp được thống nhất.

## 6. Khuyến nghị tiếp theo

- Giữ docs ở dạng “canonical + stable”.
- Khi tạo prompt mới hoặc feature mới, nên cập nhật luôn module/contract tương ứng.
- Không để docs rơi vào archive trừ khi thật sự cần historical reference.
