"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { apiRequest } from "@/lib/api";

export const NOTIFICATION_QUERY_KEYS = {
    inbox: ["notifications", "inbox"] as const,
    dashboard: ["dashboard"] as const,
    reports: ["reports", "notifications"] as const,
};

export type InboxRow = {
    id: number;
    type: string;
    title: string;
    body?: string | null;
    read_at?: string | null;
};

type InboxPage = { data?: InboxRow[] } | InboxRow[];

async function fetchInbox(): Promise<{ rows: InboxRow[]; unread: number }> {
    const [page, count] = await Promise.all([
        apiRequest("/notifications") as Promise<InboxPage>,
        apiRequest("/notifications/unread-count") as Promise<{ unread?: number }>,
    ]);
    const rows = Array.isArray(page) ? page : (page?.data ?? []);
    return { rows, unread: Number(count?.unread ?? 0) };
}

const liveOptions = {
    staleTime: 0,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
    refetchOnMount: "always" as const,
};

export function useNotificationInbox(enabled: boolean) {
    const query = useQuery({
        queryKey: NOTIFICATION_QUERY_KEYS.inbox,
        queryFn: fetchInbox,
        enabled,
        ...liveOptions,
    });

    return query;
}

export function useInvalidateNotifications() {
    const queryClient = useQueryClient();
    return () => {
        void queryClient.invalidateQueries({ queryKey: NOTIFICATION_QUERY_KEYS.inbox });
        void queryClient.invalidateQueries({ queryKey: NOTIFICATION_QUERY_KEYS.dashboard });
        void queryClient.invalidateQueries({ queryKey: NOTIFICATION_QUERY_KEYS.reports });
    };
}
