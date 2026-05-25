/** Phím tắt công cụ — dùng Ctrl+Shift để tránh xung đột trình duyệt (B, P, …). */
export const TOOL_SHORTCUT_LABELS = {
    brush: 'Ctrl+Shift+B',
    rect: 'Ctrl+Shift+M',
    ellipse: 'Ctrl+Shift+O',
    polygon: 'Ctrl+Shift+P',
    eyedropper: 'Ctrl+Shift+I',
};

/**
 * @param {KeyboardEvent} e
 * @returns {'brush'|'rect'|'ellipse'|'polygon'|'eyedropper'|null}
 */
export function toolFromKeyboardEvent(e) {
    const mod = e.ctrlKey || e.metaKey;
    if (!mod || !e.shiftKey || e.altKey) {
        return null;
    }

    const key = e.key.toLowerCase();

    if (key === 'b') {
        return 'brush';
    }
    if (key === 'm' || key === 'r') {
        return 'rect';
    }
    if (key === 'o') {
        return 'ellipse';
    }
    if (key === 'p' || key === 'l') {
        return 'polygon';
    }
    if (key === 'i') {
        return 'eyedropper';
    }

    return null;
}

/** Danh sách phím tắt hiển thị trong panel — đồng bộ với handler trong MagicEraserApp. */
export const MAGIC_ERASER_SHORTCUT_GROUPS = [
    {
        id: 'navigate',
        label: 'Di chuyển & thu phóng',
        items: [
            { keys: ['Space'], desc: 'Giữ + kéo — di chuyển ảnh' },
            { keys: ['H'], desc: 'Giữ — di chuyển ảnh (tay)' },
            { keys: ['Cuộn'], desc: 'Pan khi không giữ Ctrl' },
            { keys: ['Ctrl', 'Cuộn'], desc: 'Zoom tại vị trí con trỏ' },
            { keys: ['Ctrl', '+'], desc: 'Phóng to' },
            { keys: ['Ctrl', '−'], desc: 'Thu nhỏ' },
            { keys: ['Ctrl', '0'], desc: 'Vừa khung' },
        ],
    },
    {
        id: 'tools',
        label: 'Công cụ',
        items: [
            { keys: ['Ctrl', 'Shift', 'B'], desc: 'Cọ vẽ vùng chọn' },
            { keys: ['Ctrl', 'Shift', 'M'], desc: 'Hình chữ nhật' },
            { keys: ['Ctrl', 'Shift', 'O'], desc: 'Hình tròn / elip' },
            { keys: ['Ctrl', 'Shift', 'P'], desc: 'Đa giác' },
            { keys: ['Ctrl', 'Shift', 'I'], desc: 'Hút màu' },
            { keys: ['['], desc: 'Giảm cỡ cọ' },
            { keys: [']'], desc: 'Tăng cỡ cọ' },
        ],
    },
    {
        id: 'edit',
        label: 'Chỉnh sửa',
        items: [
            { keys: ['Enter'], desc: 'Tô màu vùng chọn / đóng đa giác' },
            { keys: ['Ctrl', 'D'], desc: 'Bỏ chọn (xóa mask)' },
            { keys: ['Ctrl', 'Z'], desc: 'Hoàn tác' },
            { keys: ['Ctrl', 'Y'], desc: 'Làm lại' },
            { keys: ['Ctrl', 'Shift', 'Z'], desc: 'Làm lại (thay Y)' },
            { keys: ['Backspace'], desc: 'Xóa điểm cuối (đa giác)' },
            { keys: ['Esc'], desc: 'Hủy đa giác / đóng trình chỉnh sửa' },
        ],
    },
    {
        id: 'file',
        label: 'Tệp',
        items: [
            { keys: ['Ctrl', 'S'], desc: 'Lưu ảnh' },
        ],
    },
];
