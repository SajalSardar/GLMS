import { NavLink } from 'react-router-dom'
import en from '../../locales/en.json'

const navItems = [
  { to: '/', label: en['nav.dashboard'] },
  { to: '/training', label: en['nav.training'] },
  { to: '/students', label: en['nav.students'] },
  { to: '/reports', label: en['nav.reports'] },
]

export default function Sidebar() {
  return (
    <nav className="sidebar" aria-label="Primary">
      <ul>
        {navItems.map((item) => (
          <li key={item.to}>
            <NavLink
              to={item.to}
              className={({ isActive }) => (isActive ? 'active' : undefined)}
              end={item.to === '/'}
            >
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
