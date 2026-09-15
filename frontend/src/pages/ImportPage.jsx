import React, { useState } from 'react';
import toast from 'react-hot-toast';
import FileDropZone from '../components/features/import/FileDropZone';
import ImportSummary from '../components/features/import/ImportSummary';
import SelectedFilePanel from '../components/features/import/SelectedFilePanel';
import PreviewTable from '../components/features/import/PreviewTable';
import { importService } from '../services/importService';

const ImportPage = () => {
    const [selectedFile, setSelectedFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [summary, setSummary] = useState(null);
    const [previewData, setPreviewData] = useState([]);

    const handleFileSelect = (file) => {
        const validExtensions = ['xlsx', 'xls', 'txt'];
        const extension = file.name.split('.').pop().toLowerCase();

        if (!validExtensions.includes(extension)) {
            toast.error('รองรับเฉพาะไฟล์ .xlsx, .xls, .txt');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            toast.error('ขนาดไฟล์ต้องไม่เกิน 10 MB');
            return;
        }

        setSelectedFile(file);
        setSummary(null);
        setPreviewData([]);
    };

    const handleClear = () => {
        setSelectedFile(null);
        setSummary(null);
        setPreviewData([]);
    };

    const handleImport = async () => {
        if (!selectedFile) return;

        setLoading(true);
        try {
            const result = await importService.uploadFile(selectedFile);

            setSummary(result.import);
            setPreviewData(result.preview || []);
            toast.success('นำเข้าข้อมูลสำเร็จ!');

        } catch (error) {
            const message = error.response?.data?.message || 'เกิดข้อผิดพลาด';
            toast.error(message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="max-w-7xl mx-auto">
            {/* Header ของหน้า (optional — Header หลักมีอยู่แล้ว) */}
            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">
                    นำเข้าข้อมูลการเงิน
                </h2>
                <p className="text-gray-500 text-sm">
                    รองรับไฟล์ Excel (.xlsx) และ Text (.txt)
                </p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left: Drop Zone + Preview Table */}
                <div className="lg:col-span-2 space-y-6">
                    <FileDropZone
                        onFileSelect={handleFileSelect}
                        selectedFile={selectedFile}
                        onClear={handleClear}
                    />

                    {previewData.length > 0 && (
                        <PreviewTable data={previewData} />
                    )}
                </div>

                {/* Right: Summary + Selected File */}
                <div className="space-y-6">
                    {selectedFile && !summary && (
                        <SelectedFilePanel
                            file={selectedFile}
                            onImport={handleImport}
                            onCancel={handleClear}
                            loading={loading}
                        />
                    )}

                    {summary && (
                        <ImportSummary summary={summary} />
                    )}
                </div>
            </div>
        </div>
    );
};

export default ImportPage;