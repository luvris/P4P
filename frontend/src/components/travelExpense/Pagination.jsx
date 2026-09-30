/** ตัวเลื่อนหน้าแบบเรียบง่ายสำหรับรายการใบเบิก */
const Pagination = ({ meta, onPageChange }) => {
    if (!meta || meta.last_page <= 1) return null;

    return (
        <div className="flex items-center justify-between text-sm text-gray-600">
            <span>
                แสดง {meta.from}-{meta.to} จาก {meta.total} รายการ
            </span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={meta.current_page <= 1}
                    onClick={() => onPageChange(meta.current_page - 1)}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                >
                    ก่อนหน้า
                </button>
                <span>
                    หน้า {meta.current_page} / {meta.last_page}
                </span>
                <button
                    type="button"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => onPageChange(meta.current_page + 1)}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40"
                >
                    ถัดไป
                </button>
            </div>
        </div>
    );
};

export default Pagination;
