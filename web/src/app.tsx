import { AuthProvider } from '@/auth/AuthContext';
import { RequireAdmin, RequireAuth } from '@/auth/RequireAuth';
import ForgotPassword from '@/pages/auth/forgot-password';
import Login from '@/pages/auth/login';
import ResetPassword from '@/pages/auth/reset-password';
import CorporationEdit from '@/pages/corporation/edit';
import CustomersIndex from '@/pages/customers/index';
import CustomersShow from '@/pages/customers/show';
import InboxIndex from '@/pages/inbox/index';
import Appearance from '@/pages/settings/appearance';
import LanguageSettings from '@/pages/settings/language';
import PasswordSettings from '@/pages/settings/password';
import StaffIndex from '@/pages/staff/index';
import UploadPhotos from '@/pages/uploads/photos';
import { Navigate, Route, Routes } from 'react-router-dom';

export function App() {
    return (
        <AuthProvider>
            <Routes>
                <Route path="/login" element={<Login />} />
                <Route path="/forgot-password" element={<ForgotPassword />} />
                <Route path="/reset-password/:token" element={<ResetPassword />} />
                {/* The customer's photo upload page. No account behind it —
                    the token in the URL is the whole authority. */}
                <Route path="/u/:token" element={<UploadPhotos />} />

                <Route element={<RequireAuth />}>
                    <Route path="/" element={<Navigate to="/inbox" replace />} />
                    <Route path="/inbox" element={<InboxIndex />} />
                    {/* A conversation being drafted, before its first message exists. */}
                    <Route path="/inbox/new" element={<InboxIndex />} />
                    <Route path="/inbox/:id" element={<InboxIndex />} />
                    {/* The dashboard is gone; old links land in the inbox. */}
                    <Route path="/dashboard" element={<Navigate to="/inbox" replace />} />
                    <Route path="/customers" element={<CustomersIndex />} />
                    <Route path="/customers/:id" element={<CustomersShow />} />
                    {/* Name/email are admin-managed from the Staff list now,
                        and self-service account deletion is gone — nothing
                        legitimate was ever on a Profile settings page beyond
                        those two, so Settings starts on Password instead. */}
                    <Route path="/settings" element={<Navigate to="/settings/password" replace />} />
                    <Route path="/settings/password" element={<PasswordSettings />} />
                    <Route path="/settings/appearance" element={<Appearance />} />
                    <Route path="/settings/language" element={<LanguageSettings />} />

                    <Route element={<RequireAdmin />}>
                        <Route path="/staff" element={<StaffIndex />} />
                        <Route path="/corporation" element={<CorporationEdit />} />
                    </Route>
                </Route>
            </Routes>
        </AuthProvider>
    );
}
