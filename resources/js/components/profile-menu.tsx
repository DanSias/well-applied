import { OverflowMenu, type MenuItem } from '@/components/overflow-menu';
import {
    PROFILE_MENU,
    activeProfileItem,
    initialsFrom,
} from '@/lib/navigation';

/**
 * The active career profile, as an initials avatar with the personal destinations behind it: Career Data and Resume
 * Profiles. It stands for the profile in use, not a signed-in account (there is no sign-in), so it offers nothing about
 * accounts. It is the shared menu: a real button, arrows move between items, Escape or an outside click closes it, and
 * focus returns to the avatar.
 */
export function ProfileMenu({
    name,
    url,
}: {
    name: string | null | undefined;
    url: string;
}) {
    const initials = initialsFrom(name);
    const current = activeProfileItem(url);
    const items: MenuItem[] = PROFILE_MENU.map((item) => ({
        label: item.label,
        href: item.href,
        current: current === item.key,
    }));

    return (
        <OverflowMenu
            label={name ? `Profile menu for ${name}` : 'Profile menu'}
            items={items}
            triggerClassName={
                current !== null
                    ? 'bg-accent-soft text-accent-text ring-accent/40 hover:bg-accent-soft'
                    : 'bg-raised text-fg ring-line-strong'
            }
            triggerContent={
                <span
                    aria-hidden="true"
                    className="text-xs font-semibold tracking-tight"
                >
                    {initials || '·'}
                </span>
            }
            roundTrigger
        />
    );
}
