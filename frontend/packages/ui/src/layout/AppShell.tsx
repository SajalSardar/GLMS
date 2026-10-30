import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar, { type SidebarItem } from './Sidebar'
import './AppShell.css'

export interface AppShellProps {
  title: string
  navItems: SidebarItem[]
}

export default function AppShell({ title, navItems }: AppShellProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="app-shell">
      <header className="app-header">
        <button
          type="button"
          className="sidebar-toggle"
          aria-label="Toggle navigation"
          aria-expanded={sidebarOpen}
          onClick={() => setSidebarOpen((open) => !open)}
        >
          ☰
        </button>
        <h1>{title}</h1>
      </header>

      <div className="app-body">
        <aside className={`app-sidebar ${sidebarOpen ? 'open' : ''}`}>
          <Sidebar items={navItems} />
        </aside>

        <main className="app-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
