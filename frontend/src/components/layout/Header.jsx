import React from 'react';
import { Menu, Calendar, ChevronDown } from 'lucide-react';
import useAuth from '../../hooks/useAuth';

const Header = ({ title = 'Dashboard' }) => {
  const { user } = useAuth();

  return (
    <header className="bg-white border-b border-gray-200 px-6 py-4">
      <div className="flex items-center justify-between">
        {/* Left */}
        <div className="flex items-center gap-4">
          <button className="p-2 hover:bg-gray-100 rounded-lg">
            <Menu size={20} />
          </button>
          <h1 className="text-xl font-semibold text-[#8B5E3C]">{title}</h1>
        </div>

        {/* Right */}
        <div className="flex items-center gap-4">
          {/* ปีงบ */}
          <div className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg">
            <Calendar size={16} className="text-gray-500" />
            <span className="text-sm text-gray-700">ปีงบประมาณ 2569</span>
            <ChevronDown size={14} className="text-gray-400" />
          </div>

          {/* User */}
          <div className="flex items-center gap-3 pl-4 border-l border-gray-200">
            <div className="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
              <span className="text-sm font-semibold text-gray-600">
                {user?.name?.[0] || 'U'}
              </span>
            </div>
            <div className="hidden md:block">
              <div className="text-sm font-medium text-gray-700">{user?.name}</div>
              <div className="text-xs text-gray-500">
                {user?.role === 'admin' ? 'ผู้ดูแลระบบ' : 
                 user?.role === 'hr' ? 'เจ้าหน้าที่ HR' : 'เจ้าหน้าที่การเงิน'}
              </div>
            </div>
            <ChevronDown size={16} className="text-gray-400" />
          </div>
        </div>
      </div>
    </header>
  );
};

export default Header;