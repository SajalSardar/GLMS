import { useLocale } from '../lib/locale'

const currentYear = new Date().getFullYear()

export default function SiteFooter() {
  const { t } = useLocale()

  // Absolute `/#id` targets, not bare `#id` — this footer renders on every
  // page via SiteShell, but the sections these jump to (#features/#classes/
  // #subjects/#help) only exist on the landing page.
  const navLinks: Array<{ href: string; label: string }> = [
    { href: '/#features', label: t['landing.nav.features'] },
    { href: '/#classes', label: t['landing.nav.classes'] },
    { href: '/#subjects', label: t['landing.nav.subjects'] },
    { href: '/#help', label: t['landing.nav.help'] },
  ]

  return (
    <footer className="bg-gradient-to-r from-primary-800 to-primary-600 text-white">
      <div className="mx-auto flex max-w-[76rem] flex-col gap-8 px-5 pt-10 pb-8 md:flex-row md:flex-wrap md:justify-between">
        <div className="flex items-start gap-3">
          <img
            src="/logo.webp"
            alt=""
            aria-hidden="true"
            className="h-10 w-10 flex-shrink-0 rounded-full bg-white object-contain p-0.5"
          />
          <div>
            <p className="m-0 font-semibold">{t['landing.brandFull']}</p>
            <p className="mt-1 text-[0.85rem] text-primary-100">{t['landing.footer.tagline']}</p>
          </div>
        </div>

        <nav className="flex flex-wrap items-center gap-4 text-[0.85rem] text-primary-100" aria-label="এক নজরে">
          <p className="m-0 w-full font-semibold text-white">{t['landing.footer.linksHeading']}</p>
          {navLinks.map((link) => (
            <a key={link.href} href={link.href} className="hover:text-white hover:underline">
              {link.label}
            </a>
          ))}
        </nav>

        <div>
          <p className="m-0 font-semibold">{t['landing.footer.helpHeading']}</p>
          <p className="mt-[0.4rem] max-w-[40ch] text-[0.85rem] text-primary-100">{t['landing.footer.helpBody']}</p>
        </div>

        <p className="m-0 w-full border-t border-white/20 pt-6 text-center text-[0.78rem] text-primary-100">
          © {currentYear} {t['landing.brand']} — {t['landing.footer.copyright']}
        </p>
      </div>
    </footer>
  )
}
