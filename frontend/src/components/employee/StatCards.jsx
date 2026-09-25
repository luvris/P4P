import { memo, useMemo } from 'react';
import { Users, UserCheck, Briefcase, Building2, UserCircle, User } from 'lucide-react';

const StatCards = ({ stats, loading = false }) => {
    // Icon mapping สำหรับประเภทต่างๆ
    const iconMap = {
        'ข้าราชการ': { icon: UserCheck, color: 'bg-emerald-100 text-emerald-700' },
        'พนักงานราชการ': { icon: Briefcase, color: 'bg-blue-100 text-blue-700' },
        'พนักงานกระทรวงสาธารณสุข': { icon: Building2, color: 'bg-yellow-100 text-yellow-700' },
        'พนักงานกระทรวง': { icon: Building2, color: 'bg-yellow-100 text-yellow-700' },
        'ลูกจ้าง': { icon: UserCircle, color: 'bg-purple-100 text-purple-700' },
        'ลูกจ้างประจำ': { icon: UserCircle, color: 'bg-purple-100 text-purple-700' },
        'ลูกจ้างชั่วคราว': { icon: User, color: 'bg-pink-100 text-pink-700' },
    };

    // สร้าง cards แบบ dynamic จาก stats ที่ได้รับมา
    const cards = useMemo(() => {
        if (!stats) return [];

        const result = [
            { key: 'total', label: 'บุคลากรทั้งหมด', icon: Users, color: 'bg-green-100 text-green-700' }
        ];

        // เพิ่มประเภทอื่นๆ จาก by_type
        if (stats.by_type) {
            Object.entries(stats.by_type).forEach(([name, count]) => {
                const mapping = iconMap[name] || { icon: User, color: 'bg-gray-100 text-gray-700' };
                result.push({
                    key: name,
                    label: name,
                    icon: mapping.icon,
                    color: mapping.color
                });
            });
        }

        return result;
    }, [stats]);

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