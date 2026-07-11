---
name: design-omichannel-dashboard
description: Thiết kế, tái cấu trúc và kiểm tra giao diện dashboard SaaS cho dự án Omichannel/Filament/Livewire theo phong cách sạch, compact, data-first và có hierarchy rõ. Dùng khi tạo hoặc sửa dashboard, settings, API connections, tables, KPI cards, charts, tabs, filters, empty/loading/error states, responsive layout; khi giao diện hiện tại trông thô, rời rạc, quá nhiều khung, sai mật độ hoặc không bám mockup tham chiếu.
---

# Design Omichannel Dashboard
Đọc trước:

- references/visual-system.md
- references/qa-checklist.md

## Mục tiêu

Tạo giao diện giống một sản phẩm SaaS trưởng thành: yên tĩnh, sắc nét, nhiều dữ liệu nhưng dễ quét. Bám design language hiện có của Omichannel và Filament; không biến mỗi yêu cầu thành một design system mới.

Đọc [visual-system.md](references/visual-system.md) trước khi thay đổi UI. Đọc [qa-checklist.md](references/qa-checklist.md) trước khi bàn giao.

## Quy trình bắt buộc

### 1. Ground vào source và tài liệu

- Đọc `@SUPER_MAP_INDEX.md`, lần tới MAP liên quan và xem local docs là nguồn mới nhất.
- Tìm page, Blade partial, Livewire/Filament class, service, translations và tests đang điều khiển màn hình.
- Kiểm tra component, CSS token và pattern đã có trước khi tạo mới.
- Nếu có ảnh tham chiếu, coi ảnh là specification về hierarchy, density và composition; không sao chép số liệu demo.

### 2. Audit trước khi code

Báo ngắn:

- Giao diện hiện tại đang sai ở hierarchy, grouping, density hay semantics nào.
- Dữ liệu thật nào đã có; dữ liệu nào chưa có.
- Component/partial nào giữ lại, tách ra hoặc sửa.
- File dự kiến thay đổi.

Không code trước báo cáo này khi người dùng yêu cầu prompt cho Cursor.

### 3. Thiết kế từ thông tin, không từ card

Xác định theo thứ tự:

1. Mục tiêu chính của trang.
2. Context và bộ lọc toàn trang.
3. Trạng thái kết nối/nguồn dữ liệu.
4. KPI quan trọng.
5. Quan hệ hoặc xu hướng cần chart.
6. Bảng dữ liệu và actions.
7. Empty/loading/error states.

Chỉ dùng card khi cần tạo một nhóm có ranh giới. Không bọc card trong card nếu typography và spacing đã đủ phân nhóm.

### 4. Bảo toàn semantics dữ liệu

- Không trộn metric từ hai provider khác nghĩa.
- Không dựng chart, comparison, quota hoặc trend giả.
- Không để filter có vẻ hoạt động nếu backend chưa dùng nó.
- Khi provider có bộ dữ liệu khác nhau, tách source tabs hoặc sections thay vì ép cùng một dashboard.
- Phân biệt rõ `not configured`, `connected`, `syncing`, `empty success`, `stale`, `failed`.

### 5. Implement theo component

- Ưu tiên component/partial tái sử dụng cho page header, source tabs, connection strip, KPI cards, charts, distributions, tables và states.
- Giữ business logic khỏi Blade nếu service/computed property phù hợp hơn.
- Dùng design tokens/class pattern hiện tại; tránh inline style lặp lại.
- Giữ URL state, domain context, permission, translations và responsive behavior.
- Không rewrite toàn trang khi có thể cải thiện composition bằng các component hiện tại.

### 6. Visual QA bắt buộc

Kiểm tra tối thiểu:

- Desktop rộng khoảng 1440px.
- Laptop khoảng 1280px.
- Mobile hoặc breakpoint nhỏ nhất app hỗ trợ.
- Có dữ liệu.
- Chưa cấu hình.
- Đã kết nối nhưng chưa sync.
- Empty thành công.
- Loading/syncing.
- Error dài và text dài.

So sánh với ảnh tham chiếu theo hierarchy, alignment, density và visual weight. Sửa cho đến khi không còn lỗi tràn, lệch grid, card cao thấp vô lý hoặc khoảng trắng ngẫu nhiên.

## Luật giao diện cứng

- Dùng một page title rõ, một subtitle ngắn; không lặp title trong panel bên dưới.
- Đặt global filters cùng hàng page header trên desktop và wrap có chủ đích trên màn hình nhỏ.
- Dùng connection strip ngang, thấp và nhẹ; không dùng các ô trống cao ngang KPI.
- KPI cùng hàng phải cùng chiều cao và cùng baseline.
- Giá trị KPI là visual focus; label nhỏ hơn; delta/status dùng màu có tiết chế.
- Dùng border xám nhạt, nền trắng, shadow rất nhẹ hoặc không shadow.
- Dùng green làm accent cho active/success/primary action; không phủ xanh toàn màn hình.
- Chỉ một primary action nổi bật trong một vùng. Secondary actions dùng outline/ghost.
- Tabs dùng underline hoặc subtle active background; không biến tất cả thành nút lớn.
- Bảng ưu tiên scanability: header nhẹ, row height gọn, số căn phải, actions không lấn dữ liệu.
- Empty state vẫn giữ khung bố cục ổn định nhưng không chiếm chiều cao vô nghĩa.
- Không dùng icon nếu icon không giúp nhận dạng hoặc thao tác.
- Không tạo gradient trang trí, glassmorphism, shadow dày, pill tràn lan hoặc màu provider quá mạnh.

## Tiêu chuẩn bàn giao

- Chạy tests liên quan và formatter/linter phù hợp.
- Nêu rõ phần dùng dữ liệu thật, phần intentionally empty và phần chưa tích hợp.
- Cập nhật MAP qua `@SUPER_MAP_INDEX.md` nếu cấu trúc, route, state hoặc data contract thay đổi.
- Không tự ý sửa business logic ngoài phạm vi design trừ khi cần thiết để UI phản ánh đúng dữ liệu; khi đó báo rõ.
