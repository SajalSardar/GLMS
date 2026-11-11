import { useId, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { studentLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import './LandingLogin.css'

const t = getCommonStrings('bn')
const currentYear = new Date().getFullYear()

type IconName =
  | 'classRange'
  | 'video'
  | 'material'
  | 'exam'
  | 'progress'
  | 'anywhere'
  | 'logo'
  | 'eyeOpen'
  | 'eyeClosed'

const NAV_LINKS: Array<{ href: string; label: string }> = [
  { href: '#features', label: t['landing.nav.features'] },
  { href: '#classes', label: t['landing.nav.classes'] },
  { href: '#subjects', label: t['landing.nav.subjects'] },
  { href: '#help', label: t['landing.nav.help'] },
]

const HERO_BADGES: Array<{ icon: IconName; tone: string; label: string }> = [
  { icon: 'video', tone: 'violet', label: t['landing.hero.badgeVideo'] },
  { icon: 'material', tone: 'green', label: t['landing.hero.badgeMaterial'] },
  { icon: 'exam', tone: 'amber', label: t['landing.hero.badgeExam'] },
  { icon: 'progress', tone: 'blue', label: t['landing.hero.badgeProgress'] },
]

const FEATURES: Array<{ icon: IconName; tone: string; title: string; desc: string }> = [
  { icon: 'classRange', tone: 'violet', title: t['landing.features.classRangeTitle'], desc: t['landing.features.classRangeDesc'] },
  { icon: 'video', tone: 'green', title: t['landing.features.videoTitle'], desc: t['landing.features.videoDesc'] },
  { icon: 'material', tone: 'amber', title: t['landing.features.materialTitle'], desc: t['landing.features.materialDesc'] },
  { icon: 'exam', tone: 'blue', title: t['landing.features.examTitle'], desc: t['landing.features.examDesc'] },
  { icon: 'progress', tone: 'rose', title: t['landing.features.progressTitle'], desc: t['landing.features.progressDesc'] },
  { icon: 'anywhere', tone: 'teal', title: t['landing.features.anywhereTitle'], desc: t['landing.features.anywhereDesc'] },
]

const CLASS_LEVELS: Array<{ tone: string; icon: IconName; label: string; range: string }> = [
  { tone: 'green', icon: 'material', label: t['landing.classes.primary'], range: t['landing.classes.primaryRange'] },
  { tone: 'blue', icon: 'video', label: t['landing.classes.secondary'], range: t['landing.classes.secondaryRange'] },
  { tone: 'amber', icon: 'exam', label: t['landing.classes.ssc'], range: t['landing.classes.sscRange'] },
  { tone: 'violet', icon: 'classRange', label: t['landing.classes.hsc'], range: t['landing.classes.hscRange'] },
]

const SUBJECTS: Array<{ glyph: string; tone: string; label: string }> = [
  { glyph: 'গ', tone: 'violet', label: t['landing.subjects.math'] },
  { glyph: 'বা', tone: 'rose', label: t['landing.subjects.bangla'] },
  { glyph: 'A', tone: 'green', label: t['landing.subjects.english'] },
  { glyph: 'বি', tone: 'violet', label: t['landing.subjects.science'] },
  { glyph: 'তথ্য', tone: 'blue', label: t['landing.subjects.ict'] },
  { glyph: 'সমাজ', tone: 'teal', label: t['landing.subjects.social'] },
  { glyph: 'ধর্ম', tone: 'rose', label: t['landing.subjects.religion'] },
  { glyph: 'কৃষি', tone: 'green', label: t['landing.subjects.agriculture'] },
  { glyph: 'স্বাস্থ্য', tone: 'red', label: t['landing.subjects.health'] },
  { glyph: '...', tone: 'muted', label: t['landing.subjects.other'] },
]

const CLASS_OPTIONS = CLASS_LEVELS.map((level) => `${level.label} (${level.range})`)
const SUBJECT_OPTIONS = SUBJECTS.map((subject) => subject.label)

function Icon({ name }: { name: IconName }) {
  const common = {
    width: 24,
    height: 24,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  }

  switch (name) {
    case 'video':
      return (
        <svg {...common}>
          <circle cx="12" cy="12" r="9" />
          <path d="M10 8.5v7l6-3.5-6-3.5Z" />
        </svg>
      )
    case 'material':
      return (
        <svg {...common}>
          <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v18H6.5A2.5 2.5 0 0 1 4 18.5v-13Z" />
          <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v18h5.5a2.5 2.5 0 0 0 2.5-2.5v-13Z" />
        </svg>
      )
    case 'exam':
      return (
        <svg {...common}>
          <rect x="5" y="3.5" width="14" height="17" rx="2" />
          <path d="m9 12 2 2 4-4.5" />
        </svg>
      )
    case 'progress':
      return (
        <svg {...common}>
          <path d="M4 20V10M11 20V4M18 20v-7" />
        </svg>
      )
    case 'classRange':
      return (
        <svg {...common}>
          <path d="M2 9.5 12 5l10 4.5-10 4.5-10-4.5Z" />
          <path d="M6 11.5V16c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-4.5" />
        </svg>
      )
    case 'anywhere':
      return (
        <svg {...common}>
          <rect x="6" y="2.5" width="12" height="19" rx="2.2" />
          <path d="M11 18.2h2" />
        </svg>
      )
    case 'logo':
      return (
        <svg {...common} strokeWidth={1.6}>
          <path d="M3 9.5 12 5l9 4.5-9 4.5-9-4.5Z" />
          <path d="M7 11.6V16c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-4.4" />
          <path d="M19.5 10v4.5" />
        </svg>
      )
    case 'eyeOpen':
      return (
        <svg {...common}>
          <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12Z" />
          <circle cx="12" cy="12" r="2.6" />
        </svg>
      )
    case 'eyeClosed':
      return (
        <svg {...common}>
          <path d="M3 3l18 18" />
          <path d="M9.9 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 6.5 10 7a12.6 12.6 0 0 1-3.2 3.7M6.6 6.7A12.7 12.7 0 0 0 2 12s3.5 6.5 10 6.5c1.3 0 2.5-.2 3.6-.7" />
          <path d="M9.9 13.1a2.6 2.6 0 0 0 3.6 3.1" />
        </svg>
      )
  }
}

function HeroIllustration({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 320 260" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
      <circle cx="160" cy="130" r="118" fill="var(--color-primary-tint)" />
      <g transform="translate(60 100)">
        <path
          d="M0 10 L100 -14 L200 10 L100 34 Z"
          fill="var(--color-surface)"
          stroke="var(--color-primary)"
          strokeWidth="3"
          strokeLinejoin="round"
        />
        <path d="M100 34 L100 70" stroke="var(--color-primary)" strokeWidth="3" strokeLinecap="round" />
        <path
          d="M30 2 L30 46 Q100 72 170 46 L170 2"
          fill="none"
          stroke="var(--color-primary-dark)"
          strokeWidth="3"
          strokeLinejoin="round"
        />
      </g>
      <circle cx="78" cy="56" r="7" fill="var(--color-accent)" />
      <circle cx="246" cy="74" r="5" fill="var(--color-accent)" />
      <circle cx="252" cy="176" r="6" fill="var(--color-primary)" />
      <circle cx="56" cy="190" r="4" fill="var(--color-primary)" />
    </svg>
  )
}

function LoginCard({ onSuccess }: { onSuccess: () => void }) {
  const { setToken } = useAuth()
  const studentIdFieldId = useId()
  const passwordFieldId = useId()

  const [studentId, setStudentId] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [showContactNote, setShowContactNote] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await studentLogin(studentId.trim(), password, 'web')
      setToken(result.token)
      onSuccess()
    } catch {
      setError(t['landing.login.errorGeneric'])
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form className="login-card" onSubmit={handleSubmit} noValidate>
      <p className="login-card__title">{t['landing.login.title']}</p>
      <p className="login-card__subtitle">{t['landing.login.subtitle']}</p>

      <label className="login-card__label" htmlFor={studentIdFieldId}>
        {t['landing.login.studentId']}
      </label>
      <input
        id={studentIdFieldId}
        className="login-card__input"
        type="text"
        autoComplete="username"
        placeholder={t['landing.login.studentIdPlaceholder']}
        value={studentId}
        onChange={(event) => setStudentId(event.target.value)}
        required
      />

      <label className="login-card__label" htmlFor={passwordFieldId}>
        {t['landing.login.password']}
      </label>
      <div className="login-card__password-row">
        <input
          id={passwordFieldId}
          className="login-card__input"
          type={showPassword ? 'text' : 'password'}
          autoComplete="current-password"
          placeholder={t['landing.login.passwordPlaceholder']}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
        />
        <button
          type="button"
          className="login-card__eye"
          aria-label={showPassword ? t['landing.login.hidePassword'] : t['landing.login.showPassword']}
          onClick={() => setShowPassword((show) => !show)}
        >
          <Icon name={showPassword ? 'eyeClosed' : 'eyeOpen'} />
        </button>
      </div>

      {error && (
        <p className="login-card__error" role="alert">
          {error}
        </p>
      )}

      <button className="login-card__submit" type="submit" disabled={submitting}>
        {submitting ? t['landing.login.submitting'] : t['landing.login.submit']}
      </button>

      <button
        type="button"
        className="login-card__link"
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.forgot']}
      </button>

      <div className="login-card__divider">
        <span>{t['landing.login.or']}</span>
      </div>

      <button
        type="button"
        className="login-card__secondary"
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.needHelp']}
      </button>

      {showContactNote && <p className="login-card__note">{t['landing.login.contactNote']}</p>}
    </form>
  )
}

export default function LandingLogin() {
  const { token } = useAuth()
  const [navOpen, setNavOpen] = useState(false)
  const [loggedInJustNow, setLoggedInJustNow] = useState(false)

  const isAuthenticated = Boolean(token) || loggedInJustNow

  return (
    <div className="landing">
      <header className="landing-nav">
        <a className="landing-nav__brand" href="#top">
          <span className="landing-nav__mark" aria-hidden="true">
            <Icon name="logo" />
          </span>
          <span className="landing-nav__wordtext">
            <span className="landing-nav__wordmark">{t['landing.brand']}</span>
            <span className="landing-nav__tagline">{t['landing.tagline']}</span>
          </span>
        </a>

        <button
          type="button"
          className="landing-nav__toggle"
          aria-label="মেনু খুলুন"
          aria-expanded={navOpen}
          onClick={() => setNavOpen((open) => !open)}
        >
          <span />
          <span />
          <span />
        </button>

        <nav
          className={`landing-nav__links${navOpen ? ' landing-nav__links--open' : ''}`}
          aria-label="প্রধান মেনু"
        >
          <a href="#top" className="landing-nav__active" onClick={() => setNavOpen(false)}>
            {t['landing.nav.home']}
          </a>
          {NAV_LINKS.map((link) => (
            <a key={link.href} href={link.href} onClick={() => setNavOpen(false)}>
              {link.label}
            </a>
          ))}
          <span className="landing-nav__actions">
            <a className="landing-nav__signin" href="#login" onClick={() => setNavOpen(false)}>
              {t['landing.nav.signIn']}
            </a>
            <a className="landing-nav__register" href="#login" onClick={() => setNavOpen(false)}>
              {t['landing.nav.register']}
            </a>
          </span>
        </nav>
      </header>

      <main>
        <section className="hero" id="top">
          <div className="hero__login" id="login">
            {isAuthenticated ? (
              <div className="login-card login-card--already">
                <p className="login-card__title">{t['landing.login.alreadyTitle']}</p>
                <Link className="login-card__submit login-card__submit--link" to="/dashboard">
                  {t['landing.login.alreadyCta']}
                </Link>
              </div>
            ) : (
              <LoginCard onSuccess={() => setLoggedInJustNow(true)} />
            )}
          </div>

          <div className="hero__intro">
            <h1 className="hero__heading">
              {t['landing.hero.heading1']}
              <br />
              {t['landing.hero.heading2']}
            </h1>
            <p className="hero__subheading">
              {t['landing.hero.sub1']}
              <br />
              {t['landing.hero.sub2']}
            </p>

            <ul className="hero__badges">
              {HERO_BADGES.map((badge) => (
                <li key={badge.label}>
                  <span className={`hero__badge-icon tone-${badge.tone}`}>
                    <Icon name={badge.icon} />
                  </span>
                  {badge.label}
                </li>
              ))}
            </ul>

            <form
              className="hero__filter"
              onSubmit={(event) => {
                event.preventDefault()
                document.getElementById('classes')?.scrollIntoView({ behavior: 'smooth' })
              }}
            >
              <label className="sr-only" htmlFor="hero-filter-class">
                {t['landing.hero.filterClassLabel']}
              </label>
              <select id="hero-filter-class" defaultValue="">
                <option value="" disabled>
                  {t['landing.hero.filterClassAll']}
                </option>
                {CLASS_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>

              <label className="sr-only" htmlFor="hero-filter-subject">
                {t['landing.hero.filterSubjectLabel']}
              </label>
              <select id="hero-filter-subject" defaultValue="">
                <option value="" disabled>
                  {t['landing.hero.filterSubjectAll']}
                </option>
                {SUBJECT_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>

              <button type="submit">{t['landing.hero.filterSubmit']}</button>
            </form>

            <figure className="hero__illustration-wrap">
              <HeroIllustration className="hero__illustration" />
              <figcaption>
                {t['landing.hero.illustrationCaption1']}
                <br />
                {t['landing.hero.illustrationCaption2']}
              </figcaption>
            </figure>
          </div>
        </section>

        <section className="feature-grid" id="features">
          <h2>{t['landing.features.heading']}</h2>
          <div className="feature-grid__cards">
            {FEATURES.map((feature) => (
              <div className="feature-card" key={feature.title}>
                <span className={`feature-card__icon tone-${feature.tone}`}>
                  <Icon name={feature.icon} />
                </span>
                <p className="feature-card__title">{feature.title}</p>
                <p className="feature-card__desc">{feature.desc}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="class-band" id="classes">
          <div className="class-band__intro">
            <h2>{t['landing.classes.heading']}</h2>
            <p>{t['landing.classes.body']}</p>
            <a className="class-band__intro-cta" href="#login">
              {t['landing.cta.button']}
            </a>
          </div>
          <div className="class-band__grid">
            {CLASS_LEVELS.map((level) => (
              <div className={`class-band__card tone-${level.tone}`} key={level.label}>
                <span className="class-band__icon">
                  <Icon name={level.icon} />
                </span>
                <p className="class-band__label">{level.label}</p>
                <p className="class-band__range">{level.range}</p>
                <a className="class-band__cta" href="#login">
                  {t['landing.classes.cta']}
                </a>
              </div>
            ))}
          </div>
        </section>

        <section className="subjects" id="subjects">
          <h2>{t['landing.subjects.heading']}</h2>
          <ul className="subjects__grid">
            {SUBJECTS.map((subject) => (
              <li key={subject.label}>
                <span className={`subjects__glyph tone-${subject.tone}`}>{subject.glyph}</span>
                {subject.label}
              </li>
            ))}
          </ul>
        </section>

        <section className="spotlight">
          <div className="spotlight__cta">
            <HeroIllustration className="spotlight__illustration" />
            <h2>{t['landing.cta.heading']}</h2>
            <p>{t['landing.cta.body']}</p>
            <a href="#login">{t['landing.cta.button']}</a>
          </div>

          <div className="spotlight__stats">
            <h2>{t['landing.stats.heading']}</h2>
            <div className="spotlight__stat-grid">
              <div>
                <strong>{t['landing.stats.classesValue']}</strong>
                <span>{t['landing.stats.classesLabel']}</span>
              </div>
              <div>
                <strong>{t['landing.stats.subjectsValue']}</strong>
                <span>{t['landing.stats.subjectsLabel']}</span>
              </div>
              <div>
                <strong>{t['landing.stats.portalsValue']}</strong>
                <span>{t['landing.stats.portalsLabel']}</span>
              </div>
            </div>
          </div>

          <blockquote className="spotlight__quote">
            <p>&ldquo;{t['landing.quote.text']}&rdquo;</p>
            <cite>— {t['landing.quote.author']}</cite>
          </blockquote>
        </section>

        <section className="community">
          <div className="community__onboarding" id="help">
            <h2>{t['landing.onboarding.heading']}</h2>
            <ol>
              <li>{t['landing.onboarding.step1']}</li>
              <li>{t['landing.onboarding.step2']}</li>
              <li>{t['landing.onboarding.step3']}</li>
            </ol>
          </div>

          <div className="community__audience">
            <h2>{t['landing.audience.heading']}</h2>
            <div className="community__audience-grid">
              <div className="community__audience-card tone-green">
                <p className="community__audience-title">{t['landing.audience.studentTitle']}</p>
                <ul>
                  <li>{t['landing.audience.studentItem1']}</li>
                  <li>{t['landing.audience.studentItem2']}</li>
                  <li>{t['landing.audience.studentItem3']}</li>
                </ul>
              </div>
              <div className="community__audience-card tone-blue">
                <p className="community__audience-title">{t['landing.audience.teacherTitle']}</p>
                <ul>
                  <li>{t['landing.audience.teacherItem1']}</li>
                  <li>{t['landing.audience.teacherItem2']}</li>
                  <li>{t['landing.audience.teacherItem3']}</li>
                </ul>
              </div>
              <div className="community__audience-card tone-rose">
                <p className="community__audience-title">{t['landing.audience.guardianTitle']}</p>
                <ul>
                  <li>{t['landing.audience.guardianItem1']}</li>
                  <li>{t['landing.audience.guardianItem2']}</li>
                  <li>{t['landing.audience.guardianItem3']}</li>
                </ul>
              </div>
            </div>
          </div>

          <div className="community__closing">
            <Icon name="classRange" />
            <h2>{t['landing.closing.heading']}</h2>
            <p>{t['landing.closing.body']}</p>
          </div>
        </section>
      </main>

      <footer className="landing-footer">
        <div className="landing-footer__brand">
          <span className="landing-nav__mark" aria-hidden="true">
            <Icon name="logo" />
          </span>
          <div>
            <p className="landing-footer__brand-name">{t['landing.brandFull']}</p>
            <p className="landing-footer__tagline">{t['landing.footer.tagline']}</p>
          </div>
        </div>

        <nav className="landing-footer__links" aria-label="এক নজরে">
          <p>{t['landing.footer.linksHeading']}</p>
          {NAV_LINKS.map((link) => (
            <a key={link.href} href={link.href}>
              {link.label}
            </a>
          ))}
        </nav>

        <div className="landing-footer__help">
          <p>{t['landing.footer.helpHeading']}</p>
          <p>{t['landing.footer.helpBody']}</p>
        </div>

        <p className="landing-footer__copyright">
          © {currentYear} {t['landing.brand']} — {t['landing.footer.copyright']}
        </p>
      </footer>
    </div>
  )
}
