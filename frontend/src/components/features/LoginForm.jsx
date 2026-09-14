import React, { useState } from 'react';
import { User, Lock, ArrowRight } from 'lucide-react';
import Input from '../ui/Input';
import Button from '../ui/Button';

const LoginForm = ({ onSubmit, loading, error }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  const handleSubmit = (e) => {
    e.preventDefault();
    onSubmit(username, password);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <Input
        label="ชื่อผู้ใช้ (Username)"
        icon={User}
        type="text"
        value={username}
        onChange={(e) => setUsername(e.target.value)}
        placeholder="กรุณากรอกชื่อผู้ใช้"
        required
      />

      <Input
        label="รหัสผ่าน (Password)"
        icon={Lock}
        type="password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder="กรุณากรอกรหัสผ่าน"
        required
      />

      {error && (
        <div className="text-red-500 text-sm text-center bg-red-50 py-2 rounded">
          {error}
        </div>
      )}

      <Button
        type="submit"
        icon={ArrowRight}
        loading={loading}
      >
        เข้าสู่ระบบ
      </Button>
    </form>
  );
};

export default LoginForm;