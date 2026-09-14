import React from 'react';
import logo from '../../assets/logo-m.png';

const HospitalBranding = () => {
    return (
        <div className="flex flex-col items-center text-center max-w-lg">
            {/* Logo */}
            <div className="w-32 h-32 mb-6 relative flex items-center justify-center">
                {/* แทนที่ SVG นี้ด้วย <img src="/logo.png" /> ถ้ามีรูปจริง */}
                {/* <svg 
          viewBox="0 0 24 24" 
          fill="none" 
          stroke="#C5A059" 
          strokeWidth="1.5" 
          className="w-24 h-24"
        >
          <circle cx="12" cy="12" r="10" />
          <circle cx="12" cy="10" r="3" />
          <path d="M6 20c0-3 3-5 6-5s6 2 6 5" />
        </svg> */}

                <img
                    src={logo}
                    alt="Hospital Logo"
                    className="w-[154px] h-[154px] object-contain"
                />
            </div>

            {/* Hospital Name */}
            <h1 className="text-4xl md:text-5xl font-bold text-[#8B5E3C] mb-2 font-serif">
                โรงพยาบาลประสาทเชียงใหม่
            </h1>

            {/* Subtitle */}
            <div className="flex items-center gap-2 text-[#8B5E3C]/80 text-lg font-medium tracking-wide">
                <span className="w-8 h-[1px] bg-[#8B5E3C]/50"></span>
                Staff Access • HR & Finance
                <span className="w-8 h-[1px] bg-[#8B5E3C]/50"></span>
            </div>
        </div>
    );
};

export default HospitalBranding;