# Omichannel Visual System

## 1. Design character

Phong cách mục tiêu: professional SaaS, calm, compact, data-first. Màn hình phải có cảm giác được thiết kế theo một grid thống nhất, không phải tập hợp các Filament components mặc định.

## 2. Composition

### Page shell

- Content width dùng toàn vùng hợp lý, có lề đều.
- Page header gồm title/subtitle bên trái và context controls bên phải.
- Khoảng cách giữa các section lớn hơn khoảng cách bên trong component.
- Dùng grid rõ: KPI grid, chart/distribution grid, table full width.

### Connection strip

- Chiều cao compact.
- Provider icon + name + status + một dòng metadata.
- Actions nằm cuối strip.
- Nhiều provider thì dùng các segment cân đối; provider ít dữ liệu không được tạo một card rỗng khổng lồ.

### KPI cards

- Label 12–14px equivalent, muted.
- Value nổi bật, tabular numbers khi có thể.
- Delta/status nhỏ, không cạnh tranh với value.
- 4–6 cards/hàng tùy viewport; responsive giảm cột tự nhiên.
- Không dùng KPI card để chứa đoạn văn hoặc form.

### Charts and distributions

- Chart chỉ dùng khi có time-series thật.
- Legend, unit, date range và tooltip phải nhất quán.
- Average position: chiều cải thiện phải được diễn giải đúng; số giảm có thể là tốt.
- Distribution dùng bar/table compact khi cần so sánh chính xác.

### Tables

- Search/filter bar tách nhẹ khỏi table header.
- Căn phải metric số; giữ keyword/query và URL dễ quét.
- Truncate có tooltip/title khi cần, không làm row cao bất thường.
- Row actions đặt cuối hàng; primary inline action dùng text accent vừa phải.

## 3. Spacing rhythm

Dùng nhịp spacing nhất quán theo token/framework hiện có, tương đương:

- 4px: icon/text micro gap.
- 8px: control nội bộ nhỏ.
- 12px: nội dung compact.
- 16px: card padding tiêu chuẩn.
- 24px: khoảng cách section.
- 32px: chỉ dùng cho tách khối lớn.

Không dùng padding lớn để che việc thiếu hierarchy.

## 4. Typography

- Một H1/page title.
- Section title rõ nhưng nhỏ hơn nhiều so với page title.
- Body text trung tính; helper text muted.
- Không dùng bold cho mọi label.
- Không viết hoa toàn bộ ngoại trừ acronym tự nhiên như GSC, CTR, API.

## 5. States

### Not configured

Nói provider nào chưa cấu hình và cung cấp một CTA đến settings.

### Connected, not mapped

Hiển thị kết nối thành công nhưng domain hiện tại cần mapping.

### Mapped, not synced

Hiển thị property và CTA sync.

### Empty success

Nói sync thành công nhưng date range/filter không có dữ liệu. Không dùng màu lỗi.

### Failed

Hiển thị lỗi ngắn, action retry và details có thể mở; không đổ stack trace vào layout.

### Loading

Giữ kích thước layout bằng skeleton hoặc disabled state; tránh layout shift.

## 6. Anti-patterns

- Hai hàng title lặp nhau.
- Card cao chỉ chứa một dòng status.
- Nút xanh ở mọi nơi, tất cả cùng visual priority.
- Empty box cao hàng trăm pixel với chữ “Not synced”.
- Sáu KPI cards nhưng phần lớn là `No data` từ provider khác.
- Trộn GSC impressions/clicks với rank tracking visibility/volume.
- Filter toolbar rời rạc, label nằm trên control không đều.
- Dựng mock data để dashboard trông đầy.

