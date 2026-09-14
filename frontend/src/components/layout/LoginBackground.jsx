import React from 'react';

const LoginBackground = ({ children }) => {
  return (
    <div className="min-h-screen w-full flex items-center justify-center relative overflow-hidden font-sans">
      {/* Background layers */}
      <div className="absolute inset-0 z-0">
        <div className="absolute inset-0 bg-gradient-to-br from-[#fdfbf7] via-[#f4f1ea] to-[#dcd6cc]"></div>
        <div className="absolute bottom-0 left-0 w-full h-1/2 bg-gradient-to-tr from-transparent via-yellow-200/20 to-transparent transform -skew-y-12 blur-3xl"></div>
        <div className="absolute top-0 right-0 w-1/2 h-full bg-gradient-to-bl from-orange-100/30 to-transparent blur-3xl"></div>
      </div>

      {/* Content */}
      <div className="container mx-auto px-4 z-10">
        {children}
      </div>
    </div>
  );
};

export default LoginBackground;