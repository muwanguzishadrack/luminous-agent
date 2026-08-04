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
    const slug = page.props.team?.slug;

    const teamHref = (
        route: (args: string | number) => RouteDefinition<'get'>,
    ): NavItem['href'] => (slug ? route(slug) : '/');

    const platformNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: teamHref(dashboard),
            icon: LayoutGrid,
        },
        {
            title: 'Inbox',
            href: teamHref(inbox),
            icon: MessagesSquare,
        },
    ];

    const audienceNavItems: NavItem[] = [
        {
            title: 'Contacts',
            href: teamHref(contacts),
            icon: Users,
        },
        {
            title: 'Segments',
            href: teamHref(segments),
            icon: Filter,
        },
    ];

    const messagingNavItems: NavItem[] = [
        {
            title: 'Templates',
            href: teamHref(templates),
            icon: LayoutTemplate,
        },
        {
            title: 'Campaigns',
            href: teamHref(campaigns),
            icon: Megaphone,
        },
        {
            title: 'Sequences',
            href: teamHref(sequences),
            icon: Workflow,
        },
    ];

    const businessNavItems: NavItem[] = [
        {
            title: 'AI Agent',
            href: teamHref(agent),
            icon: Bot,
        },
        {
            title: 'Commerce',
            href: teamHref(commerce),
            icon: ShoppingBag,
        },
        {
            title: 'Growth',
            href: teamHref(growth),
            icon: TrendingUp,
        },
        {
            title: 'Analytics',
            href: teamHref(analytics),
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
                            <Link href={teamHref(dashboard)} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
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
