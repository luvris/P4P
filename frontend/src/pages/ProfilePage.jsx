import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import {
  AtSign,
  BadgeCheck,
  Check,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Lock,
  Mail,
  Save,
  ShieldCheck,
  User,
} from 'lucide-react';

import { profileService } from '../services/profileService';

/* ============================================================
 * ข้อมูล role + สิทธิ์การใช้งาน (อ้างอิงจาก routes/api.php)
 * ============================================================ */
const ROLE_META = {
  admin: {
    label: 'ผู้ดูแลระบบ',
    badge: 'bg-purple-100 text-purple-700 border-purple-200',
    avatar: 'from-[#C5A059] to-[#8B5E3C]',
    description: 'เข้าถึงได้ทุกโมดูลของระบบ',
    permissions: [
      'บริหารงานบุคคลและรายชื่อบุคลากร',
      'นำเข้าข้อมูลบุคลากร',
      'คำนวณเงินสำรองและปรับฐานเงินเดือน',
      'นำเข้าข้อมูลการเงิน',
      'จัดทำใบเบิกค่าใช้จ่ายเดินทางไปราชการ',
    ],
  },
  hr: {
    label: 'เจ้าหน้าที่ HR',
    badge: 'bg-amber-100 text-amber-700 border-amber-200',
    avatar: 'from-[#C5A059] to-[#8B5E3C]',
    description: 'ดูแลงานบริหารงานบุคคล',
    permissions: [
      'บริหารงานบุคคลและรายชื่อบุคลากร',
      'นำเข้าข้อมูลบุคลากร',
      'คำนวณเงินสำรองและปรับฐานเงินเดือน',
    ],
  },
  finance: {
    label: 'เจ้าหน้าที่การเงิน',
    badge: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    avatar: 'from-emerald-500 to-emerald-700',
    description: 'ดูแลงานการเงิน',
    permissions: [
      'ดูรายชื่อบุคลากร (อ่านอย่างเดียว)',
      'นำเข้าข้อมูลการเงิน',
      'จัดทำใบเบิกค่าใช้จ่ายเดินทางไปราชการ',
    ],
  },
};

const DEFAULT_ROLE_META = {
  label: 'ผู้ใช้งาน',
  badge: 'bg-gray-100 text-gray-700 border-gray-200',
  avatar: 'from-gray-400 to-gray-600',
  description: '',
  permissions: [],
};

const getRoleMeta = (role) => ROLE_META[role] || DEFAULT_ROLE_META;

const readStoredUser = () => {
  try {
    const stored = localStorage.getItem('user');
    return stored ? JSON.parse(stored) : null;
  } catch {
    return null;
  }
};

/* บันทึก user ล่าสุดลง localStorage + แจ้ง Header ให้อัปเดตทันที */
const syncStoredUser = (user) => {
  localStorage.setItem('user', JSON.stringify(user));
  window.dispatchEvent(new CustomEvent('user-updated', { detail: user }));
};

const INPUT_CLASS =
  'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 placeholder:text-gray-400 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059] disabled:bg-gray-50 disabled:text-gray-500';

/* ดึงข้อความ error แรกจาก Laravel validation ให้แสดงใน toast */
const firstError = (err, fallback) => {
  const errors = err?.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)?.[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return err?.response?.data?.message || fallback;
};

const ProfilePage = () => {
  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);
  const [form, setForm] = useState({ name: '', email: '' });
  const [savingProfile, setSavingProfile] = useState(false);

  const [pwForm, setPwForm] = useState({ current: '', next: '', confirm: '' });
  const [savingPassword, setSavingPassword] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    let active = true;

    (async () => {
      try {
        const data = await profileService.getProfile();
        if (!active) return;
        setProfile(data.user);
        setForm({ name: data.user.name || '', email: data.user.email || '' });
        syncStoredUser(data.user);
      } catch (err) {
        if (!active) return;
        // ใช้ข้อมูลจาก localStorage แทนถ้าเรียก API ไม่สำเร็จ
        const stored = readStoredUser();
        if (stored) {
          setProfile(stored);
          setForm({ name: stored.name || '', email: stored.email || '' });
        }
        toast.error(firstError(err, 'ไม่สามารถโหลดข้อมูลโปรไฟล์ได้'));
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, []);

  const roleMeta = getRoleMeta(profile?.role);
  const initial = profile?.name?.trim()?.[0]?.toUpperCase() || 'U';
  const dirty = profile
    ? form.name.trim() !== (profile.name || '') || form.email.trim() !== (profile.email || '')
    : false;

  const handleSaveProfile = async (e) => {
    e.preventDefault();

    const name = form.name.trim();
    const email = form.email.trim();

    if (!name) {
      toast.error('กรุณากรอกชื่อ-นามสกุล');
      return;
    }
    if (!email) {
      toast.error('กรุณากรอกอีเมล');
      return;
    }
    if (!dirty) {
      toast('ยังไม่มีการเปลี่ยนแปลง', { icon: 'ℹ️' });
      return;
    }

    setSavingProfile(true);
    try {
      const data = await profileService.updateProfile({ name, email });
      setProfile(data.user);
      setForm({ name: data.user.name || '', email: data.user.email || '' });
      syncStoredUser(data.user);
      toast.success(data.message || 'บันทึกข้อมูลส่วนตัวสำเร็จ');
    } catch (err) {
      toast.error(firstError(err, 'บันทึกข้อมูลไม่สำเร็จ'));
    } finally {
      setSavingProfile(false);
    }
  };

  const handleChangePassword = async (e) => {
    e.preventDefault();

    const { current, next, confirm } = pwForm;

    if (!current) {
      toast.error('กรุณากรอกรหัสผ่านเดิม');
      return;
    }
    if (next.length < 8) {
      toast.error('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
      return;
    }
    if (next !== confirm) {
      toast.error('รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน');
      return;
    }

    setSavingPassword(true);
    try {
      const data = await profileService.changePassword({
        currentPassword: current,
        password: next,
        passwordConfirmation: confirm,
      });
      toast.success(data.message || 'เปลี่ยนรหัสผ่านสำเร็จ');
      setPwForm({ current: '', next: '', confirm: '' });
    } catch (err) {
      toast.error(firstError(err, 'เปลี่ยนรหัสผ่านไม่สำเร็จ'));
    } finally {
      setSavingPassword(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24 text-gray-400">
        <Loader2 className="w-6 h-6 animate-spin mr-2" />
        <span className="text-sm">กำลังโหลดข้อมูลโปรไฟล์...</span>
      </div>
    );
  }

  return (
    <div className="space-y-4 max-w-5xl">
      {/* ==== การ์ดข้อมูลบัญชี ==== */}
      <div className="rounded-2xl border border-[#E6D3A3] bg-white p-6">
        <div className="flex flex-col sm:flex-row sm:items-center gap-5">
          <div
            className={`w-20 h-20 shrink-0 rounded-full bg-gradient-to-br ${roleMeta.avatar} flex items-center justify-center shadow-sm`}
          >
            <span className="text-3xl font-semibold text-white">{initial}</span>
          </div>

          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-xl font-semibold text-[#8B5E3C] truncate">
                {profile?.name || 'ไม่ทราบชื่อ'}
              </h2>
              <span
                className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium ${roleMeta.badge}`}
              >
                <ShieldCheck className="w-3.5 h-3.5" />
                {roleMeta.label}
              </span>
            </div>

            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
              <span className="inline-flex items-center gap-1.5 min-w-0">
                <AtSign className="w-4 h-4 shrink-0" />
                <span className="truncate">{profile?.username || '-'}</span>
              </span>
              <span className="inline-flex items-center gap-1.5 min-w-0">
                <Mail className="w-4 h-4 shrink-0" />
                <span className="truncate">{profile?.email || '-'}</span>
              </span>
            </div>

            {roleMeta.description && (
              <p className="mt-2 text-xs text-gray-400">{roleMeta.description}</p>
            )}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4 items-start">
        {/* ==== ข้อมูลส่วนตัว ==== */}
        <div className="lg:col-span-3 rounded-2xl border border-[#E6D3A3] bg-white p-6">
          <div className="flex items-center gap-2 mb-5">
            <div className="rounded-lg bg-amber-100 p-2 text-amber-700">
              <User className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-semibold text-gray-800">ข้อมูลส่วนตัว</h3>
              <p className="text-xs text-gray-500">แก้ไขชื่อที่แสดงและอีเมลติดต่อของคุณ</p>
            </div>
          </div>

          <form onSubmit={handleSaveProfile} className="space-y-4">
            <div>
              <label htmlFor="profile-name" className="block text-sm font-medium text-gray-700 mb-1.5">
                ชื่อ-นามสกุล
              </label>
              <div className="relative">
                <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  id="profile-name"
                  type="text"
                  value={form.name}
                  onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                  className={`${INPUT_CLASS} pl-9`}
                  placeholder="เช่น สมชาย ใจดี"
                  autoComplete="name"
                />
              </div>
            </div>

            <div>
              <label htmlFor="profile-email" className="block text-sm font-medium text-gray-700 mb-1.5">
                อีเมล
              </label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  id="profile-email"
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm((prev) => ({ ...prev, email: e.target.value }))}
                  className={`${INPUT_CLASS} pl-9`}
                  placeholder="name@cmneuro.go.th"
                  autoComplete="email"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label htmlFor="profile-username" className="block text-sm font-medium text-gray-700 mb-1.5">
                  ชื่อผู้ใช้
                </label>
                <div className="relative">
                  <AtSign className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    id="profile-username"
                    type="text"
                    value={profile?.username || ''}
                    className={`${INPUT_CLASS} pl-9`}
                    disabled
                  />
                </div>
              </div>

              <div>
                <label htmlFor="profile-role" className="block text-sm font-medium text-gray-700 mb-1.5">
                  บทบาทในระบบ
                </label>
                <div className="relative">
                  <BadgeCheck className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    id="profile-role"
                    type="text"
                    value={roleMeta.label}
                    className={`${INPUT_CLASS} pl-9`}
                    disabled
                  />
                </div>
              </div>
            </div>

            <p className="text-xs text-gray-400">
              ชื่อผู้ใช้และบทบาทเป็นข้อมูลของระบบ ไม่สามารถแก้ไขได้ — ติดต่อผู้ดูแลระบบหากต้องการเปลี่ยน
            </p>

            <div className="flex justify-end pt-1">
              <button
                type="submit"
                disabled={savingProfile || !dirty}
                className="inline-flex items-center gap-2 rounded-lg bg-[#8B5E3C] px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#734A2E] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {savingProfile ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Save className="w-4 h-4" />
                )}
                บันทึกข้อมูล
              </button>
            </div>
          </form>
        </div>

        {/* ==== เปลี่ยนรหัสผ่าน + สิทธิ์การใช้งาน ==== */}
        <div className="lg:col-span-2 space-y-4">
          <div className="rounded-2xl border border-[#E6D3A3] bg-white p-6">
            <div className="flex items-center gap-2 mb-5">
              <div className="rounded-lg bg-rose-100 p-2 text-rose-700">
                <KeyRound className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-semibold text-gray-800">เปลี่ยนรหัสผ่าน</h3>
                <p className="text-xs text-gray-500">ตั้งรหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร</p>
              </div>
            </div>

            <form onSubmit={handleChangePassword} className="space-y-4">
              <div>
                <label htmlFor="pw-current" className="block text-sm font-medium text-gray-700 mb-1.5">
                  รหัสผ่านเดิม
                </label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    id="pw-current"
                    type={showPassword ? 'text' : 'password'}
                    value={pwForm.current}
                    onChange={(e) => setPwForm((prev) => ({ ...prev, current: e.target.value }))}
                    className={`${INPUT_CLASS} pl-9`}
                    placeholder="••••••••"
                    autoComplete="current-password"
                  />
                </div>
              </div>

              <div>
                <label htmlFor="pw-new" className="block text-sm font-medium text-gray-700 mb-1.5">
                  รหัสผ่านใหม่
                </label>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    id="pw-new"
                    type={showPassword ? 'text' : 'password'}
                    value={pwForm.next}
                    onChange={(e) => setPwForm((prev) => ({ ...prev, next: e.target.value }))}
                    className={`${INPUT_CLASS} pl-9 pr-10`}
                    placeholder="อย่างน้อย 8 ตัวอักษร"
                    autoComplete="new-password"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((prev) => !prev)}
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-gray-400 hover:text-gray-600"
                    aria-label={showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div>
                <label htmlFor="pw-confirm" className="block text-sm font-medium text-gray-700 mb-1.5">
                  ยืนยันรหัสผ่านใหม่
                </label>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    id="pw-confirm"
                    type={showPassword ? 'text' : 'password'}
                    value={pwForm.confirm}
                    onChange={(e) => setPwForm((prev) => ({ ...prev, confirm: e.target.value }))}
                    className={`${INPUT_CLASS} pl-9`}
                    placeholder="กรอกรหัสผ่านใหม่อีกครั้ง"
                    autoComplete="new-password"
                  />
                </div>
              </div>

              <div className="flex justify-end pt-1">
                <button
                  type="submit"
                  disabled={savingPassword}
                  className="inline-flex items-center gap-2 rounded-lg border border-[#C5A059] bg-[#FDFBF7] px-5 py-2.5 text-sm font-medium text-[#8B5E3C] transition-colors hover:bg-[#F5EEDF] disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {savingPassword ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <KeyRound className="w-4 h-4" />
                  )}
                  เปลี่ยนรหัสผ่าน
                </button>
              </div>
            </form>
          </div>

          {/* ==== สิทธิ์การใช้งานตามบทบาท ==== */}
          <div className="rounded-2xl border border-[#E6D3A3] bg-[#FDFBF7] p-6">
            <div className="flex items-center gap-2 mb-4">
              <ShieldCheck className="w-5 h-5 text-[#8B5E3C]" />
              <h3 className="font-semibold text-gray-800">สิทธิ์การใช้งาน</h3>
            </div>

            <ul className="space-y-2">
              {roleMeta.permissions.map((permission) => (
                <li key={permission} className="flex items-start gap-2 text-sm text-gray-600">
                  <Check className="w-4 h-4 mt-0.5 shrink-0 text-emerald-600" />
                  <span>{permission}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProfilePage;
