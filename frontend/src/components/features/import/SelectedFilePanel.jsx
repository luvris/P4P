import React from 'react';
import { X, Upload } from 'lucide-react';
import useAuth from '../../../hooks/useAuth';

const SelectedFilePanel = ({ file, onImport, onCancel, loading }) => {
    const { user } = useAuth();

    if (!file) return null;

    // Format วันที่ปัจจุบัน (พ.ศ.)
    const formatDate = () => {
        const now = new Date();
        const thaiYear = now.getFullYear() + 543;
        const day = now.getDate();
        const months = [
            'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน',
            'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม',
            'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
        ];
        const month = months[now.getMonth()];
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        return `${day} ${month} ${thaiYear} ${hours}:${minutes} น.`;
    };

    // แปลง role เป็นภาษาไทย
    const getRoleLabel = (role) => {
        switch (role) {
            case 'admin': return 'ผู้ดูแลระบบ';
            case 'hr': return 'เจ้าหน้าที่ HR';
            case 'finance': return 'เจ้าหน้าที่การเงิน';
            default: return 'ผู้ใช้งาน';
        }
    };

    return (
        <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
            <h3 className="text-lg font-semibold text-gray-700 mb-4">
                ไฟล์ที่เลือก
            </h3>

            <div className="space-y-3 mb-6">
                {/* ชื่อไฟล์ */}
                <div className="flex justify-between text-sm gap-2">
                    <span className="text-gray-500 flex-shrink-0">ชื่อไฟล์</span>
                    <span className="font-medium text-gray-700 truncate text-right">
                        {file.name}
                    </span>
                </div>

                {/* ขนาด */}
                <div className="flex justify-between text-sm">
                    <span className="text-gray-500">ขนาด</span>
                    <span className="font-medium text-gray-700">
                        {(file.size / 1024).toFixed(2)} KB
                    </span>
                </div>

                {/* ประเภท */}
                <div className="flex justify-between text-sm">
                    <span className="text-gray-500">ประเภท</span>
                    <span className="font-medium text-gray-700">
                        {file.name.split('.').pop().toUpperCase()}
                    </span>
                </div>

                {/* วันที่นำเข้า */}
                <div className="flex justify-between text-sm">
                    <span className="text-gray-500">วันที่นำเข้า</span>
                    <span className="font-medium text-gray-700">
                        {formatDate()}
                    </span>
                </div>

                {/* ผู้นำเข้า (ชื่อ) */}
                <div className="flex justify-between text-sm">
                    <span className="text-gray-500">ผู้นำเข้า</span>
                    <span className="font-medium text-gray-700">
                        {user?.name || 'ไม่ระบุ'}
                    </span>
                </div>

                {/* ฝ่ายที่นำเข้า */}
                <div className="flex justify-between text-sm">
                    <span className="text-gray-500">ฝ่ายที่นำเข้า</span>
                    <span className="font-medium text-gray-700">
                        {getRoleLabel(user?.role)}
                    </span>
                </div>
            </div>

            <div className="flex gap-3">
                <button
                    onClick={onCancel}
                    disabled={loading}
                    className="flex-1 flex items-center justify-center gap-2 px-4 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50"
                >
                    <X size={16} />
                    ยกเลิก
                </button>
                <button
                    onClick={onImport}
                    disabled={loading}
                    className="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-[#E6D3A3] hover:bg-[#C5A059] text-[#4a3b22] rounded-lg font-medium transition-colors disabled:opacity-50"
                >
                    <Upload size={16} />
                    {loading ? 'กำลังนำเข้า...' : 'นำเข้าไฟล์'}
                </button>
            </div>
        </div>
    );
};

export default SelectedFilePanel;