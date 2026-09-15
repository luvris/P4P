import React from 'react';
import { LayoutDashboard, Users, Wallet, FileSpreadsheet } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import useAuth from '../../hooks/useAuth';
import logo from '../../assets/logo-m.png';

const Sidebar = () => {
    const location = useLocation();
    const { user, hasRole } = useAuth();

    const menuItems = [
        {
            name: 'Dashboard',
            icon: LayoutDashboard,
            path: '/dashboard',
            roles: ['admin', 'hr', 'finance']
        },
        {
            name: 'บริหารงานบุคคล',
            icon: Users,
            path: '/hr',
            roles: ['admin', 'hr']
        },
        {
            name: 'งานการเงิน',
            icon: Wallet,
            path: '/finance',
            roles: ['admin', 'finance'],
            children: [
                { name: 'นำเข้าข้อมูลการเงิน', path: '/finance/import' }
            ]
        },
    ];

    return (
        <aside className="w-64 bg-white border-r border-gray-200 min-h-screen flex flex-col">
            {/* Logo */}
            <div className="p-6 border-b border-gray-100">
                <div className="flex items-center gap-3">
                    <div className="w-12 h-12 flex items-center justify-center">
                        <img
                            src={logo}
                            alt="Hospital Logo"
                            className="w-[154px] h-[154px] object-contain"
                        />
                    </div>
                    <div>
                        <div className="text-sm font-bold text-[#8B5E3C]">โรงพยาบาล</div>
                        <div className="text-sm font-bold text-[#8B5E3C]">ประสาทเชียงใหม่</div>
                        <div className="text-xs text-[#8B5E3C]/80">Chiangmai Neuro Hospital</div>
                    </div>
                </div>
            </div>

            {/* Menu */}
            <nav className="flex-1 p-4 space-y-1">
                {menuItems.map((item) => {
                    if (!hasRole(...item.roles)) return null;

                    const isActive = location.pathname.startsWith(item.path);
                    const Icon = item.icon;

                    return (
                        <div key={item.path}>
                            <Link
                                to={item.path}
                                className={`flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${isActive
                                    ? 'bg-[#F5EEDC] text-[#8B5E3C]'
                                    : 'text-gray-600 hover:bg-gray-50'
                                    }`}
                            >
                                <Icon size={20} />
                                <span className="text-sm font-medium">{item.name}</span>
                            </Link>

                            {/* Submenu */}
                            {item.children && isActive && (
                                <div className="ml-4 mt-1 space-y-1">
                                    {item.children.map((child) => (
                                        <Link
                                            key={child.path}
                                            to={child.path}
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

            {/* User Info */}
            <div className="p-4 border-t border-gray-100">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-[#F5EEDC] flex items-center justify-center">
                        <span className="text-[#8B5E3C] font-semibold">
                            {user?.name?.[0] || 'U'}
                        </span>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium text-gray-700 truncate">
                            {user?.name || 'User'}
                        </div>
                        <div className="text-xs text-gray-500">
                            {user?.role === 'admin' ? 'ผู้ดูแลระบบ' :
                                user?.role === 'hr' ? 'เจ้าหน้าที่ HR' : 'เจ้าหน้าที่การเงิน'}
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    );
};

export default Sidebar;