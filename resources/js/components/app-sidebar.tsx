import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Bot,
    ChartColumn,
    Filter,
    FolderGit2,
    LayoutGrid,
    LayoutTemplate,
    Megaphone,
    MessagesSquare,
    ShoppingBag,
    TrendingUp,
    Users,
    Workflow,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TenantSwitcher } from '@/components/tenant-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    agent,
    analytics,
    campaigns,
    commerce,
    contacts,
    dashboard,
    growth,
    inbox,
    segments,
    sequences,
    templates,
} from '@/routes';
import type { NavItem } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

export function AppSidebar() {
    const page = usePage();
    const slug = page.props.currentTenant?.slug;

    const tenantHref = (
        route: (args: string | number) => RouteDefinition<'get'>,
    ): NavItem['href'] => (slug ? route(slug) : '/');

    const platformNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: tenantHref(dashboard),
            icon: LayoutGrid,
        },
        {
            title: 'Inbox',
            href: tenantHref(inbox),
            icon: MessagesSquare,
        },
    ];

    const audienceNavItems: NavItem[] = [
        {
            title: 'Contacts',
            href: tenantHref(contacts),
            icon: Users,
        },
        {
            title: 'Segments',
            href: tenantHref(segments),
            icon: Filter,
        },
    ];

    const messagingNavItems: NavItem[] = [
        {
            title: 'Templates',
            href: tenantHref(templates),
            icon: LayoutTemplate,
        },
        {
            title: 'Campaigns',
            href: tenantHref(campaigns),
            icon: Megaphone,
        },
        {
            title: 'Sequences',
            href: tenantHref(sequences),
            icon: Workflow,
        },
    ];

    const businessNavItems: NavItem[] = [
        {
            title: 'AI Agent',
            href: tenantHref(agent),
            icon: Bot,
        },
        {
            title: 'Commerce',
            href: tenantHref(commerce),
            icon: ShoppingBag,
        },
        {
            title: 'Growth',
            href: tenantHref(growth),
            icon: TrendingUp,
        },
        {
            title: 'Analytics',
            href: tenantHref(analytics),
            icon: ChartColumn,
        },
    ];

    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: 'https://github.com/laravel/react-starter-kit',
            icon: FolderGit2,
        },
        {
            title: 'Documentation',
            href: 'https://laravel.com/docs/starter-kits#react',
            icon: BookOpen,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={tenantHref(dashboard)} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TenantSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={platformNavItems} />
                <NavMain label="Audience" items={audienceNavItems} />
                <NavMain label="Messaging" items={messagingNavItems} />
                <NavMain label="Business" items={businessNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
