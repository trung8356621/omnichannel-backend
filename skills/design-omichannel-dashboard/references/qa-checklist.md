# Visual QA Checklist

## Hierarchy

- [ ] Chỉ có một page title.
- [ ] Primary action rõ, secondary actions lùi lại.
- [ ] Nguồn dữ liệu và trạng thái kết nối hiểu được trong vài giây.
- [ ] Section order phù hợp luồng đọc: context → status → KPI → analysis → detail.

## Grid and spacing

- [ ] Mép trái/phải của header, cards, chart và table thẳng nhau.
- [ ] KPI cards cùng chiều cao và baseline.
- [ ] Khoảng cách section nhất quán.
- [ ] Không có panel rỗng hoặc chiều cao dư vô nghĩa.

## Data integrity

- [ ] Không có metric giả hoặc provider metric bị trộn.
- [ ] Filter hiển thị thực sự ảnh hưởng query/sync hoặc được disable rõ.
- [ ] Unit, date range và direction của delta chính xác.
- [ ] Domain switch đọc đúng site/property/snapshot.

## States

- [ ] Not configured.
- [ ] Connected but unmapped.
- [ ] Mapped but unsynced.
- [ ] Loading/syncing.
- [ ] Empty successful response.
- [ ] Failed response và retry.
- [ ] Stale data/last synced khi phù hợp.

## Responsive

- [ ] Controls wrap theo nhóm, không rơi từng label riêng lẻ.
- [ ] Tabs scroll/wrap có chủ đích.
- [ ] KPI grid giảm cột không tạo card quá hẹp.
- [ ] Table có horizontal strategy rõ.
- [ ] Không overflow, clipping hoặc action chồng nội dung.

## Finish

- [ ] Không lặp CSS/class có thể tái sử dụng.
- [ ] Translation đầy đủ.
- [ ] Tests/formatter liên quan chạy thành công.
- [ ] MAP được cập nhật nếu contract hoặc cấu trúc thay đổi.
