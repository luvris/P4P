import React from 'react';
import { Toaster } from 'react-hot-toast';
import Sidebar from './Sidebar';
import Header from './Header';

const DashboardLayout = ({ children, title }) => {
  return (
    <div className="flex min-h-screen bg-[#FAF8F3]">
      <Sidebar />
      <div className="flex-1 flex flex-col">
        <Header title={title} />
        <main className="flex-1 p-6">
          {children}
        </main>
      </div>

      <Toaster
        position="top-right"
        toastOptions={{
          duration: 3000,
          style: {
            fontFamily: 'Prompt, sans-serif',
            fontSize: '14px',
          },
          success: {
            style: { background: '#10b981', color: '#fff' },
            iconTheme: { primary: '#fff', secondary: '#10b981' },
          },
          error: {
            style: { background: '#ef4444', color: '#fff' },
            iconTheme: { primary: '#fff', secondary: '#ef4444' },
          },
        }}
      />
    </div>
  );
};

export default DashboardLayout;