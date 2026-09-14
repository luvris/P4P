import React from 'react';

const Input = ({ 
  label, 
  icon: Icon, 
  type = 'text', 
  value, 
  onChange, 
  placeholder, 
  required = false 
}) => {
  return (
    <div className="space-y-2">
      {label && (
        <label className="text-gray-600 text-sm font-medium">{label}</label>
      )}
      <div className="relative group">
        {Icon && (
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-[#D4AF37] transition-colors">
            <Icon size={20} />
          </div>
        )}
        <input
          type={type}
          value={value}
          onChange={onChange}
          placeholder={placeholder}
          required={required}
          className={`w-full ${Icon ? 'pl-10' : 'pl-4'} pr-4 py-3 rounded-lg border border-gray-300 
            focus:border-[#D4AF37] focus:ring-2 focus:ring-[#D4AF37]/20 
            outline-none transition-all bg-white/50`}
        />
      </div>
    </div>
  );
};

export default Input;