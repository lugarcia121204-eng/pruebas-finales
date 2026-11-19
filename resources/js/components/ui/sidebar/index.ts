import { cn } from '@/lib/utils';
import { Primitive } from 'reka-ui';
import { defineComponent, h } from 'vue';

function sidebarElement(tag: string, className: string) {
    return defineComponent({
        inheritAttrs: false,
        setup(_, { attrs, slots }) {
            return () => h(tag, { ...attrs, class: cn(className, attrs.class) }, slots.default?.());
        },
    });
}

export const SidebarProvider = defineComponent({
    props: { defaultOpen: { type: Boolean, default: true } },
    setup(props, { slots }) {
        return () => h('div', {
            class: 'group/sidebar-wrapper flex min-h-svh w-full',
            'data-state': props.defaultOpen ? 'expanded' : 'collapsed',
        }, slots.default?.());
    },
});

export const SidebarInset = sidebarElement('main', 'relative flex min-h-svh flex-1 flex-col bg-background');
export const SidebarGroup = sidebarElement('div', 'relative flex w-full min-w-0 flex-col p-2');
export const SidebarGroupLabel = sidebarElement('div', 'flex h-8 items-center px-2 text-xs font-medium text-sidebar-foreground/70');
export const SidebarGroupContent = sidebarElement('div', 'w-full text-sm');
export const SidebarMenu = sidebarElement('ul', 'flex w-full min-w-0 flex-col gap-1');
export const SidebarMenuItem = sidebarElement('li', 'group/menu-item relative');

export const SidebarMenuButton = defineComponent({
    inheritAttrs: false,
    props: {
        asChild: Boolean,
        isActive: Boolean,
        tooltip: String,
    },
    setup(props, { attrs, slots }) {
        return () => h(Primitive, {
            ...attrs,
            as: 'button',
            asChild: props.asChild,
            title: props.tooltip,
            'data-active': props.isActive,
            class: cn('flex w-full items-center gap-2 rounded-md p-2 text-left text-sm hover:bg-sidebar-accent data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium [&>svg]:size-4 [&>svg]:shrink-0', attrs.class),
        }, slots);
    },
});
