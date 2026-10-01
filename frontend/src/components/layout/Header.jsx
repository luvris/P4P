import React, { useState, useRef, useEffect } from 'react';
import { Menu, Calendar, ChevronDown, LogOut, User } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import useAuth from '../../hooks/useAuth';
import useFiscalYear from '../../hooks/useFiscalYear';

const Header = ({ title = 'Dashboard', onToggleSidebar }) => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const { fiscalYear, setFiscalYear, fiscalYearOptions } = useFiscalYear();
  const hasYearOptions = fiscalYearOptions.length > 0;

  const [dropdownOpen, setDropdownOpen] = useState(false);
  const dropdownRef = useRef(null);

  const [yearOpen, setYearOpen] = useState(false);
  const yearRef = useRef(null);

  // ปิด dropdown เมื่อคลิกข้างนอก
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setDropdownOpen(false);
      }
      if (yearRef.current && !yearRef.current.contains(e.target)) {
        setYearOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // ปิด dropdown ด้วย ESC
  useEffect(() => {
    const handleEsc = (e) => {
      if (e.key === 'Escape') {
        setDropdownOpen(false);
        setYearOpen(false);
      }
    };
    document.addEventListener('keydown', handleEsc);
    return () => document.removeEventListener('keydown', handleEsc);
  }, []);

  const handleLogout = async () => {
    // รอให้ฝั่งเซิร์ฟเวอร์ล้าง session ก่อน แล้วจึงพากลับหน้า login
    await logout();
    navigate('/', { replace: true });
  };

  const getRoleLabel = (role) => {
    if (role === 'admin') return 'ผู้ดูแลระบบ';
    if (role === 'hr') return 'เจ้าหน้าที่ HR';
    if (role === 'finance') return 'เจ้าหน้าที่การเงิน';
    return 'ผู้ใช้งาน';
  };

  return (
    <header className="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-20">
      <div className="flex items-center justify-between">
        {/* Left */}
        <div className="flex items-center gap-4">
          {/*ปุ่ม hamburger toggle */}
          <button
            type="button"
            onClick={onToggleSidebar}
            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
            aria-label="Toggle sidebar"
          >
            <Menu size={20} />
          </button>
          <h1 className="text-xl font-semibold text-[#8B5E3C]">{title}</h1>
        </div>

        {/* Right */}
        <div className="flex items-center gap-4">
          {/* ปีงบประมาณ — เปิด dropdown เฉพาะปีที่มีผลการคำนวณบันทึกไว้แล้ว */}
          <div className="relative" ref={yearRef}>
            {hasYearOptions ? (
              <button
                type="button"
                onClick={() => setYearOpen((prev) => !prev)}
                aria-haspopup="listbox"
                aria-expanded={yearOpen}
                aria-label={`ปีงบประมาณ ${fiscalYear} เปลี่ยนปีงบประมาณ`}
                className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
              >
                <Calendar size={16} className="text-gray-500" />
                <span className="text-sm text-gray-700">
                  <span className="hidden md:inline">ปีงบประมาณ </span>
                  {fiscalYear}
                </span>
                <ChevronDown
                  size={14}
                  className={`text-gray-400 transition-transform ${yearOpen ? 'rotate-180' : ''}`}
                />
              </button>
            ) : (
              <div
                className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg"
                title="ยังไม่มีปีงบประมาณที่บันทึกผลการคำนวณไว้"
              >
                <Calendar size={16} className="text-gray-500" />
                <span className="text-sm text-gray-700">
                  <span className="hidden md:inline">ปีงบประมาณ </span>
                  {fiscalYear}
                </span>
              </div>
            )}

            {hasYearOptions && yearOpen && (
              <ul
                role="listbox"
                aria-label="เลือกปีงบประมาณ"
                className="absolute right-0 top-full mt-2 w-44 max-h-64 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1"
              >
                {fiscalYearOptions.map((year) => (
                  <li key={year} role="none">
                    <button
                      type="button"
                      role="option"
                      aria-selected={year === fiscalYear}
                      onClick={() => {
                        setFiscalYear(year);
                        setYearOpen(false);
                      }}
                      className={`w-full px-4 py-2 text-left text-sm transition-colors ${
                        year === fiscalYear
                          ? 'bg-[#FBF7EE] font-medium text-[#8B5E3C]'
                          : 'text-gray-700 hover:bg-gray-50'
                      }`}
                    >
                      ปีงบประมาณ {year}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/*User dropdown */}
          <div className="relative" ref={dropdownRef}>
            <button
              type="button"
              onClick={() => setDropdownOpen((prev) => !prev)}
              className="flex items-center gap-3 pl-4 border-l border-gray-200 hover:bg-gray-50 rounded-lg py-1 pr-2 transition-colors"
            >
              <div className="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
                <span className="text-sm font-semibold text-gray-600">
                  {user?.name?.[0] || 'U'}
                </span>
              </div>
              <div className="hidden md:block text-left">
                <div className="text-sm font-medium text-gray-700">{user?.name}</div>
                <div className="text-xs text-gray-500">{getRoleLabel(user?.role)}</div>
              </div>
              <ChevronDown
                size={16}
                className={`text-gray-400 transition-transform ${dropdownOpen ? 'rotate-180' : ''}`}
              />
            </button>

            {/* Dropdown menu */}
            {dropdownOpen && (
              <div className="absolute right-0 top-full mt-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden z-50">
                {/* User info */}
                <div className="px-4 py-3 border-b border-gray-100">
                  <div className="text-sm font-medium text-gray-700 truncate">
                    {user?.name}
                  </div>
                  <div className="text-xs text-gray-500 truncate">
                    {user?.email}
                  </div>
                </div>

                {/* Menu items */}
                <button
                  type="button"
                  onClick={() => {
                    setDropdownOpen(false);
                    navigate('/profile');
                  }}
                  className="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors text-left"
                >
                  <User size={16} />
                  โปรไฟล์ของฉัน
                </button>

                <button
                  type="button"
                  onClick={handleLogout}
                  className="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors text-left border-t border-gray-100"
                >
                  <LogOut size={16} />
                  ออกจากระบบ
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  );
};

export default Header;