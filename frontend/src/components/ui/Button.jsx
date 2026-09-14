import React from 'react';
import { Loader2 } from 'lucide-react';

const Button = ({ 
  children, 
  icon: Icon, 
  loading = false, 
  type = 'button', 
  onClick, 
  disabled = false,
  fullWidth = true,
  variant = 'primary' 
}) => {
  const baseStyles = 'font-bold py-3 rounded-lg shadow-md transition-all flex items-center justify-center gap-2';
  const widthStyles = fullWidth ? 'w-full' : '';
  
  const variants = {
    primary: 'bg-gradient-to-r from-[#E6D3A3] to-[#C5A059] hover:from-[#d4c090] hover:to-[#b08d4a] text-[#4a3b22]',
    outline: 'border-2 border-[#C5A059] text-[#8B5E3C] hover:bg-[#C5A059]/10',
  };

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled || loading}
      className={`${baseStyles} ${widthStyles} ${variants[variant]} disabled:opacity-60 disabled:cursor-not-allowed`}
    >
      {loading ? (
        <>
          <Loader2 className="animate-spin" size={20} />
          <span>กำลังโหลด...</span>
        </>
      ) : (
        <>
          {Icon && <Icon size={20} />}
          <span>{children}</span>
        </>
      )}
    </button>
  );
};

export default Button;