import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import en from '../../locales/en.json'
import Sidebar from './Sidebar'
import './AppShell.css'

export default function AppShell() {
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
        <h1>{en['app.title']}</h1>
      </header>

      <div className="app-body">
        <aside className={`app-sidebar ${sidebarOpen ? 'open' : ''}`}>
          <Sidebar />
        </aside>

        <main className="app-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
