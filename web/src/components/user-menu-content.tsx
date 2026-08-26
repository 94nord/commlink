import { useAuth } from '@/auth/AuthContext';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type User } from '@/types';
import { LogOut, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { logout } = useAuth();
    const navigate = useNavigate();
    const { t } = useTranslation('nav');

    async function handleLogout() {
        cleanup();
        await logout();
        navigate('/login');
    }

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    {/* The landing route, not a specific page: /settings
                        redirects to whichever page settings currently opens
                        on. It pointed at /settings/profile, which stopped
                        existing when the profile page was removed — and an
                        unmatched route renders nothing, so Settings was a
                        blank screen rather than an error. */}
                    <Link className="block w-full" to="/settings" onClick={cleanup}>
                        <Settings className="mr-2" />
                        {t('settings')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={handleLogout}>
                <LogOut className="mr-2" />
                {t('log_out')}
            </DropdownMenuItem>
        </>
    );
}
