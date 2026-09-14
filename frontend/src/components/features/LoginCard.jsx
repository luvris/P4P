import React from 'react';
import { User } from 'lucide-react';
import LoginForm from './LoginForm';

const LoginCard = ({ onSubmit, loading, error }) => {
  return (
    <div className="w-full max-w-md bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-8 md:p-10 border border-white/50">
      
      {/* Avatar Icon */}
      <div className="flex justify-center mb-4">
        <div className="w-20 h-20 rounded-full border-2 border-[#D4AF37] flex items-center justify-center text-[#D4AF37]">
          <User size={40} strokeWidth={1.5} />
        </div>
      </div>

      {/* Title */}
      <div className="text-center mb-8">
        <h2 className="text-3xl font-bold text-[#333] mb-2">
          เข้าสู่ระบบบุคลากร
        </h2>
        <p className="text-gray-500 text-sm">
          ระบบสำหรับบุคลากรโรงพยาบาลประสาทเชียงใหม่<br />
          เพื่อเข้าถึงระบบ HR และการเงิน
        </p>
        <div className="flex items-center justify-center gap-2 mt-4">
          <span className="w-12 h-[1px] bg-gray-300"></span>
          <span className="text-[#D4AF37]">✦</span>
          <span className="w-12 h-[1px] bg-gray-300"></span>
        </div>
      </div>

      {/* Form */}
      <LoginForm 
        onSubmit={onSubmit} 
        loading={loading} 
        error={error} 
      />
    </div>
  );
};

export default LoginCard;