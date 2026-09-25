import React, { useRef, useState } from 'react';
import { Upload, X } from 'lucide-react';

const HrFileDropZone = ({ onFileSelect, selectedFile, onClear }) => {
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef(null);

    const handleDragOver = (e) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            onFileSelect(files[0]);
        }
    };

    const handleFileInput = (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            onFileSelect(files[0]);
        }
    };

    // ถ้ามีไฟล์แล้ว ให้แสดงชื่อไฟล์ + ปุ่มลบ
    if (selectedFile) {
        return (
            <div className="border-2 border-solid border-[#C5A059] bg-[#FDFBF7] rounded-2xl p-8">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <div className="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <Upload size={24} className="text-green-600" />
                        </div>
                        <div>
                            <div className="font-medium text-gray-700">{selectedFile.name}</div>
                            <div className="text-sm text-gray-500">
                                {(selectedFile.size / 1024).toFixed(2)} KB
                            </div>
                        </div>
                    </div>
                    <button
                        onClick={onClear}
                        className="p-2 hover:bg-gray-100 rounded-lg text-gray-500 hover:text-red-500"
                    >
                        <X size={20} />
                    </button>
                </div>
            </div>
        );
    }

    // ถ้ายังไม่มีไฟล์ให้แสดง Drop Zone
    return (
        <div
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            className={`border-2 border-dashed rounded-2xl p-12 text-center transition-colors ${
                isDragging
                    ? 'border-[#C5A059] bg-[#FDFBF7]'
                    : 'border-[#E6D3A3] bg-[#FDFBF7]/50'
            }`}
        >
            <div className="flex flex-col items-center gap-4">
                <div className="flex items-center gap-3">
                    <svg viewBox="0 0 24 24" className="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" strokeWidth="1.5">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    <span className="text-3xl text-gray-400">+</span>
                    <div className="w-16 h-16 bg-green-600 rounded-lg flex items-center justify-center">
                        <span className="text-white text-2xl font-bold">X</span>
                    </div>
                </div>

                <div>
                    <div className="text-xl font-semibold text-[#8B5E3C] mb-2">
                        ลากไฟล์มาวางที่นี่ หรือเลือกไฟล์
                    </div>

                    <button
                        onClick={() => fileInputRef.current?.click()}
                        className="inline-flex items-center gap-2 px-6 py-2 bg-[#E6D3A3]/50 hover:bg-[#E6D3A3] text-[#8B5E3C] rounded-lg font-medium transition-colors"
                    >
                        <Upload size={18} />
                        เลือกไฟล์
                    </button>

                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".xlsx,.xls"
                        onChange={handleFileInput}
                        className="hidden"
                    />
                </div>

                <div className="text-sm text-gray-500">
                    รองรับไฟล์ Excel (.xlsx) ขนาดไม่เกิน 10 MB
                </div>
            </div>
        </div>
    );
};

export default HrFileDropZone;