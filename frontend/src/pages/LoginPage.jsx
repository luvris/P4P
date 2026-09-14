import React from 'react';
import toast from 'react-hot-toast';
import LoginBackground from '../components/layout/LoginBackground';
import HospitalBranding from '../components/features/HospitalBranding';
import LoginCard from '../components/features/LoginCard';
import useAuth from '../hooks/useAuth';

const LoginPage = () => {
  const { login, loading, error } = useAuth();

  const handleLogin = async (username, password) => {
    const result = await login(username, password);
    
    if (result.success) {
      toast.success(`ยินดีต้อนรับ ${result.data.user.name}!`);
      // TODO: redirect ไปหน้า Dashboard
      // navigate('/dashboard');
    } else {
      toast.error(result.error);
    }
  };

  return (
    <LoginBackground>
      <div className="flex flex-col lg:flex-row items-center justify-center gap-12 lg:gap-24">
        <HospitalBranding />
        <LoginCard 
          onSubmit={handleLogin} 
          loading={loading} 
          error={error} 
        />
      </div>
    </LoginBackground>
  );
};

export default LoginPage;