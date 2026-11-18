import { NavLink } from 'react-router-dom'

export interface SidebarItem {
  to?: string
  label: string
  icon?: string
  children?: SidebarItem[]
  // Force exact-path matching for this link's active state. Needed for a
  // "Dashboard" item whose own route (e.g. "/dashboard") is a prefix of
  // every other route in the app — without this it would show active on
  // every page, not just its own. Leaf items default to prefix matching
  // (item.to === '/') so a detail sub-route (e.g. "/schools/42") still
  // highlights its parent "Schools" link.
  end?: boolean
}

export interface SidebarProps {
  items: SidebarItem[]
}

const linkBase =
  'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition-colors duration-150'
// Apps can brand the hover state without touching this shared component by
// setting --sidebar-hover-bg/--sidebar-hover-text in their own CSS; apps
// that don't define them keep the default primary-color hover.
const linkInactive =
  'text-gray-600 hover:bg-[var(--sidebar-hover-bg,var(--color-primary-50))] hover:text-[var(--sidebar-hover-text,var(--color-primary-600))]'
const linkActive = 'bg-primary-50 text-primary-600'

export default function Sidebar({ items }: SidebarProps) {
  return (
    <ul className="space-y-1">
      {items.map((item) =>
        item.children && item.children.length > 0 ? (
          <li key={item.label}>
            <details className="group">
              <summary
                className={`${linkBase} ${linkInactive} cursor-pointer list-none [&::-webkit-details-marker]:hidden`}
              >
                {item.icon && (
                  <span className="material-symbols-outlined !text-[20px] leading-none">{item.icon}</span>
                )}
                <span>{item.label}</span>
                <span className="material-symbols-outlined ml-auto !text-[18px] leading-none transition-transform group-open:rotate-90">
                  chevron_right
                </span>
              </summary>
              <ul className="mt-1 space-y-1 pl-9">
                {item.children.map((child) => (
                  <li key={child.to}>
                    <NavLink
                      to={child.to!}
                      className={({ isActive }) =>
                        `flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors duration-150 ${
                          isActive ? linkActive : linkInactive
                        }`
                      }
                    >
                      {child.icon && (
                        <span className="material-symbols-outlined !text-[18px] leading-none">{child.icon}</span>
                      )}
                      <span>{child.label}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            </details>
          </li>
        ) : (
          <li key={item.to}>
            <NavLink
              to={item.to!}
              end={item.end ?? item.to === '/'}
              className={({ isActive }) => `${linkBase} ${isActive ? linkActive : linkInactive}`}
            >
              {item.icon && (
                <span className="material-symbols-outlined !text-[20px] leading-none">{item.icon}</span>
              )}
              <span>{item.label}</span>
            </NavLink>
          </li>
        ),
      )}
    </ul>
  )
}
