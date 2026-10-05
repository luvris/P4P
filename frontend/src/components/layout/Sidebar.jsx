import React, { useState, useEffect } from 'react';
import { LayoutDashboard, Users, Wallet, Upload, ChevronDown, ChevronRight } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import useAuth from '../../hooks/useAuth';
import logo from '../../assets/logo-m.png';

const Sidebar = ({ isOpen = true, onClose }) => {
    const location = useLocation();
    const { user, hasRole } = useAuth();
    const [openMenus, setOpenMenus] = useState({});

    const menuItems = [
        {
            name: 'Dashboard',
            icon: LayoutDashboard,
            path: '/dashboard',
            // finance เข้าดูได้แบบ read-only
            roles: ['admin', 'hr', 'finance'],
        },
        {
            // ไฟล์เดียวได้ทั้งทะเบียนบุคลากรและแถวเงินเดือน จึงอยู่ระดับบนสุด
            // ให้ทุก role ใช้ร่วมกัน ไม่ต้องซ้ำสองฝั่ง
            name: 'นำเข้าข้อมูล',
            icon: Upload,
            path: '/import',
            roles: ['admin', 'hr', 'finance'],
        },
        {
            name: 'บริหารงานบุคคล',
            icon: Users,
            path: '#',
            roles: ['admin', 'hr'],
            children: [
                // { name: 'รายชื่อบุคลากร', path: '/hr' },
                { name: 'คำนวณเงินสำรอง', path: '/hr/reserve-fund' },
                { name: 'ปรับฐานเงินเดือน', path: '/hr/salary-adjustments' },
                
            ],
        },
        {
            name: 'งานการเงิน',
            icon: Wallet,
            path: '/finance/travel-expense-claims',
            roles: ['admin', 'finance'],
            children: [
                { name: 'จัดทำใบเบิกค่าใช้จ่าย', path: '/finance/travel-expense-claims' },
            ],
        },
    ];

    useEffect(() => {
        menuItems.forEach((item) => {
            if (item.children) {
                const hasActiveChild = item.children.some(
                    (child) => location.pathname === child.path
                );
                if (hasActiveChild) {
                    setOpenMenus((prev) => ({ ...prev, [item.path]: true }));
                }
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [location.pathname]);

    const toggleMenu = (path) => {
        setOpenMenus((prev) => ({ ...prev, [path]: !prev[path] }));
    };

    const handleItemClick = () => {
        if (window.innerWidth < 768) {
            onClose?.();
        }
    };

    return (
        <aside
            className={`bg-white border-r border-gray-200 flex flex-col shrink-0
                transition-all duration-300 ease-in-out
                ${isOpen ? 'w-64' : 'w-0 overflow-hidden border-r-0'}
                fixed md:sticky md:top-0 h-screen z-40 md:z-0
                ${isOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}`}
        >
            {/* Logo — shrink-0 */}
            <div className="p-6 border-b border-gray-100 shrink-0">
                <div className="flex items-center gap-3">
                    <div className="w-12 h-12 flex items-center justify-center shrink-0">
                        <img
                            src={logo}
                            alt="Hospital Logo"
                            className="w-[154px] h-[154px] object-contain"
                        />
                    </div>
                    <div className="min-w-0">
                        <div className="text-sm font-bold text-[#8B5E3C]">โรงพยาบาล</div>
                        <div className="text-sm font-bold text-[#8B5E3C]">ประสาทเชียงใหม่</div>
                        <div className="text-xs text-[#8B5E3C]/80 whitespace-nowrap">
                            Chiangmai Neuro Hospital
                        </div>
                    </div>
                </div>
            </div>

            {/* Menu — flex-1 + min-h-0 + scroll */}
            <nav className="flex-1 min-h-0 overflow-y-auto p-4 space-y-1">
                {menuItems.map((item) => {
                    if (!hasRole(...item.roles)) return null;

                    const Icon = item.icon;
                    const isActive = location.pathname.startsWith(item.path);
                    const hasChildren = item.children && item.children.length > 0;
                    const isMenuOpen = openMenus[item.path];

                    return (
                        <div key={item.path}>
                            {hasChildren ? (
                                // เมนูที่มีเมนูย่อย — คลิกที่ตัวเมนูเพื่อเปิด/ปิดเมนูย่อย (ไม่ย้ายหน้า)
                                <button
                                    type="button"
                                    onClick={() => toggleMenu(item.path)}
                                    aria-expanded={isMenuOpen}
                                    aria-controls={`submenu-${item.path}`}
                                    className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-lg transition-colors text-left ${isActive
                                            ? 'bg-[#F5EEDC] text-[#8B5E3C]'
                                            : 'text-gray-600 hover:bg-gray-50'
                                        }`}
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <Icon size={20} className="shrink-0" />
                                        <span className="text-sm font-medium truncate">{item.name}</span>
                                    </div>

                                    <span className="p-1 shrink-0" aria-hidden="true">
                                        {isMenuOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                                    </span>
                                </button>
                            ) : (
                                <Link
                                    to={item.path}
                                    onClick={handleItemClick}
                                    className={`flex items-center justify-between gap-3 px-4 py-3 rounded-lg transition-colors ${isActive
                                            ? 'bg-[#F5EEDC] text-[#8B5E3C]'
                                            : 'text-gray-600 hover:bg-gray-50'
                                        }`}
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <Icon size={20} className="shrink-0" />
                                        <span className="text-sm font-medium truncate">{item.name}</span>
                                    </div>
                                </Link>
                            )}

                            {hasChildren && isMenuOpen && (
                                <div id={`submenu-${item.path}`} className="ml-4 mt-1 space-y-1">
                                    {item.children.map((child) => (
                                        <Link
                                            key={child.path}
                                            to={child.path}
                                            onClick={handleItemClick}
                                            className={`block px-4 py-2 rounded-lg text-sm transition-colors ${location.pathname === child.path
                                                    ? 'bg-[#F5EEDC] text-[#8B5E3C] font-medium'
                                                    : 'text-gray-500 hover:bg-gray-50'
                                                }`}
                                        >
                                            {child.name}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>

            {/* User Info — shrink-0 */}
            <div className="p-4 border-t border-gray-100 shrink-0">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-[#F5EEDC] flex items-center justify-center shrink-0">
                        <span className="text-[#8B5E3C] font-semibold">
                            {user?.name?.[0] || 'U'}
                        </span>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium text-gray-700 truncate">
                            {user?.name || 'User'}
                        </div>
                        <div className="text-xs text-gray-500 truncate">
                            {user?.role === 'admin'
                                ? 'ผู้ดูแลระบบ'
                                : user?.role === 'hr'
                                    ? 'เจ้าหน้าที่ HR'
                                    : 'เจ้าหน้าที่การเงิน'}
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    );
};

export default Sidebar;