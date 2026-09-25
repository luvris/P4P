import { memo } from 'react';
import { Users, UserCheck, Briefcase, Building2, UserCircle } from 'lucide-react';

const StatCards = ({ stats, loading = false }) => {
    const cards = [
        { key: 'total', label: 'บุคลากรทั้งหมด', icon: Users, color: 'bg-green-100 text-green-700' },
        { key: 'ข้าราชการ', label: 'ข้าราชการ', icon: UserCheck, color: 'bg-emerald-100 text-emerald-700' },
        { key: 'พนักงานราชการ', label: 'พนักงานราชการ', icon: Briefcase, color: 'bg-blue-100 text-blue-700' },
        { key: 'พนักงานกระทรวงสาธารณสุข', label: 'พนักงานกระทรวงสาธารณสุข', icon: Building2, color: 'bg-yellow-100 text-yellow-700' },
        { key: 'ลูกจ้าง', label: 'ลูกจ้าง', icon: UserCircle, color: 'bg-purple-100 text-purple-700' },
    ];

    return (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
            {cards.map(({ key, label, icon: Icon, color }) => {
                const value = stats?.[key] ?? 0;
                return (
                    <div
                        key={key}
                        className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3 hover:shadow-md transition-shadow"
                    >
                        <div className={`rounded-lg p-2.5 ${color} shrink-0`}>
                            <Icon className="w-5 h-5" />
                        </div>
                        <div className="min-w-0">
                            <p className="text-xs text-gray-500 truncate">{label}</p>
                            <p className="text-2xl font-semibold text-gray-800">
                                {loading ? (
                                    <span className="inline-block w-12 h-6 bg-gray-200 rounded animate-pulse" />
                                ) : (
                                    <>
                                        {value.toLocaleString('th-TH')}
                                        <span className="text-sm font-normal text-gray-500 ml-1">คน</span>
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};

export default memo(StatCards);