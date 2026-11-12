"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { useTheme } from "./theme";
import { Icon } from "./icons";

const NAV = [
  { href: "/email-accounts", label: "Dashboard", icon: "mail" },
  { href: "/integrations", label: "Integrations", icon: "integrations" },
  { href: "/settings", label: "Settings", icon: "settings" },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const { theme, toggle } = useTheme();
  const [collapsed, setCollapsed] = useState(false);

  return (
    <div className="flex min-h-screen">
      {/* Sidebar */}
      <aside
        className={`sticky top-0 flex h-screen shrink-0 flex-col border-r border-[var(--border)] bg-[var(--surface)] transition-all ${
          collapsed ? "w-16" : "w-60"
        }`}
      >
        <div className="flex h-14 items-center gap-2 border-b border-[var(--border)] px-4">
          <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-[var(--accent)] text-[var(--accent-fg)] text-xs font-bold">
            RC
          </div>
          {!collapsed && (
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold leading-tight">RevCadence</div>
              <div className="truncate text-[10px] uppercase tracking-wide text-[var(--text-faint)]">
                Deliverability OS
              </div>
            </div>
          )}
        </div>

        <nav className="flex-1 overflow-y-auto px-2 py-3">
          {NAV.map((item) => {
            const active = pathname === item.href || pathname.startsWith(item.href + "/");
            return (
              <Link
                key={item.href}
                href={item.href}
                title={item.label}
                className={`mb-0.5 flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition ${
                  active
                    ? "bg-[var(--surface-2)] font-medium text-[var(--text)]"
                    : "text-[var(--text-muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                }`}
              >
                <Icon name={item.icon} size={18} className="shrink-0" />
                {!collapsed && <span className="truncate">{item.label}</span>}
              </Link>
            );
          })}
        </nav>

        <button
          onClick={() => setCollapsed((c) => !c)}
          className="flex items-center gap-2 border-t border-[var(--border)] px-4 py-2.5 text-xs text-[var(--text-muted)] hover:bg-[var(--surface-2)]"
        >
          <Icon name="chevron" size={16} className={collapsed ? "" : "rotate-180"} />
          {!collapsed && "Collapse"}
        </button>
      </aside>

      {/* Main */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 flex h-14 items-center justify-between gap-4 border-b border-[var(--border)] bg-[var(--surface)]/80 px-6 backdrop-blur">
          <div className="text-sm text-[var(--text-muted)]">
            {NAV.find((n) => pathname === n.href || pathname.startsWith(n.href + "/"))?.label ??
              "Dashboard"}
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={toggle}
              title="Toggle theme"
              className="rounded-lg p-2 text-[var(--text-muted)] hover:bg-[var(--surface-2)]"
            >
              <Icon name={theme === "dark" ? "sun" : "moon"} size={18} />
            </button>
            <div className="flex items-center gap-2 border-l border-[var(--border)] pl-3">
              <div className="text-right">
                <div className="text-xs font-medium leading-tight">{user?.name}</div>
                <div className="text-[10px] text-[var(--text-faint)]">{user?.role_label}</div>
              </div>
              <button
                onClick={logout}
                title="Log out"
                className="rounded-lg p-2 text-[var(--text-muted)] hover:bg-[var(--surface-2)]"
              >
                <Icon name="logout" size={18} />
              </button>
            </div>
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1500px] flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
