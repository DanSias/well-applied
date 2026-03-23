import Link from "next/link";
import { NavLinks } from "@/components/app-shell/nav-links";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex h-screen bg-zinc-50">
      {/* Sidebar */}
      <aside className="flex w-56 flex-col border-r border-zinc-200 bg-white px-3 py-4">
        <Link
          href="/dashboard"
          className="mb-6 px-3 text-lg font-bold tracking-tight text-zinc-900"
        >
          Well Applied
        </Link>
        <NavLinks />
      </aside>

      {/* Main content area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Header */}
        <header className="flex h-14 items-center justify-between border-b border-zinc-200 bg-white px-6">
          <span className="text-sm text-zinc-400">Well Applied</span>
          <div className="flex items-center gap-3">
            <span className="text-xs text-zinc-400">Profile</span>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  );
}
