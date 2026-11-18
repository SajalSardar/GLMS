import { useId, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { studentLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

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

// Tone-color utility classes, mirroring the `--tone-*` system from the old
// LandingLogin.css: `soft` = the 16%-on-white tint used for icon chips,
// `solid` = the flat tone color used for the class-level cards, `subtle` =
// the 12%-on-white tint + border color used for the audience cards. Written
// out as literal strings (not built from template pieces) so Tailwind's
// build-time class scanner can see every one of them.
const TONE_STYLES: Record<string, { soft: string; solid: string; subtle: string }> = {
  violet: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-violet)_16%,white)] text-[color:var(--color-tone-violet)]',
    solid: 'bg-[color:var(--color-tone-violet)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-violet)_12%,white)] border-[color:var(--color-tone-violet)]',
  },
  green: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-green)_16%,white)] text-[color:var(--color-tone-green)]',
    solid: 'bg-[color:var(--color-tone-green)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-green)_12%,white)] border-[color:var(--color-tone-green)]',
  },
  amber: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-amber)_16%,white)] text-[color:var(--color-tone-amber)]',
    solid: 'bg-[color:var(--color-tone-amber)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-amber)_12%,white)] border-[color:var(--color-tone-amber)]',
  },
  blue: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-blue)_16%,white)] text-[color:var(--color-tone-blue)]',
    solid: 'bg-[color:var(--color-tone-blue)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-blue)_12%,white)] border-[color:var(--color-tone-blue)]',
  },
  rose: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-rose)_16%,white)] text-[color:var(--color-tone-rose)]',
    solid: 'bg-[color:var(--color-tone-rose)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-rose)_12%,white)] border-[color:var(--color-tone-rose)]',
  },
  teal: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-teal)_16%,white)] text-[color:var(--color-tone-teal)]',
    solid: 'bg-[color:var(--color-tone-teal)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-teal)_12%,white)] border-[color:var(--color-tone-teal)]',
  },
  red: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-red)_16%,white)] text-[color:var(--color-tone-red)]',
    solid: 'bg-[color:var(--color-tone-red)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-red)_12%,white)] border-[color:var(--color-tone-red)]',
  },
  muted: {
    soft: 'bg-[color:color-mix(in_srgb,var(--color-tone-muted)_16%,white)] text-[color:var(--color-tone-muted)]',
    solid: 'bg-[color:var(--color-tone-muted)]',
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-muted)_12%,white)] border-[color:var(--color-tone-muted)]',
  },
}

const FOCUS_RING =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-accent-dark)]'

const LOGIN_CARD_TOP_BAR =
  "before:absolute before:inset-x-0 before:top-0 before:h-1 before:rounded-t-[1.1rem] before:bg-linear-to-r before:from-[color:var(--color-primary)] before:to-[color:var(--color-accent)] before:content-['']"

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
    <form
      className={`relative mx-auto max-w-[26rem] rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 pt-7 pb-6 shadow-[0_18px_40px_-24px_rgba(15,107,76,0.35)] ${LOGIN_CARD_TOP_BAR}`}
      onSubmit={handleSubmit}
      noValidate
    >
      <p className="m-0 text-[1.2rem] font-semibold font-display">{t['landing.login.title']}</p>
      <p className="mt-[0.35rem] mb-5 text-[0.9rem] text-[color:var(--color-muted)]">{t['landing.login.subtitle']}</p>

      <label className="mt-[0.9rem] mb-[0.4rem] block text-[0.85rem] font-medium" htmlFor={studentIdFieldId}>
        {t['landing.login.studentId']}
      </label>
      <input
        id={studentIdFieldId}
        className={`w-full rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-base text-[color:var(--color-ink)] focus:border-primary-500 ${FOCUS_RING}`}
        type="text"
        autoComplete="username"
        placeholder={t['landing.login.studentIdPlaceholder']}
        value={studentId}
        onChange={(event) => setStudentId(event.target.value)}
        required
      />

      <label className="mt-[0.9rem] mb-[0.4rem] block text-[0.85rem] font-medium" htmlFor={passwordFieldId}>
        {t['landing.login.password']}
      </label>
      <div className="flex items-center gap-[0.4rem]">
        <input
          id={passwordFieldId}
          className={`w-full flex-1 rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-base text-[color:var(--color-ink)] focus:border-primary-500 ${FOCUS_RING}`}
          type={showPassword ? 'text' : 'password'}
          autoComplete="current-password"
          placeholder={t['landing.login.passwordPlaceholder']}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
        />
        <button
          type="button"
          className={`flex h-10 w-10 flex-shrink-0 cursor-pointer items-center justify-center rounded-[0.6rem] border border-[color:var(--color-border)] text-[color:var(--color-muted)] ${FOCUS_RING}`}
          aria-label={showPassword ? t['landing.login.hidePassword'] : t['landing.login.showPassword']}
          onClick={() => setShowPassword((show) => !show)}
        >
          <Icon name={showPassword ? 'eyeClosed' : 'eyeOpen'} />
        </button>
      </div>

      {error && (
        <p className="mt-[0.9rem] rounded-lg bg-[#fbe9e7] px-3 py-[0.6rem] text-[0.85rem] text-[#9c2b22]" role="alert">
          {error}
        </p>
      )}

      <button
        className={`mt-5 block w-full cursor-pointer rounded-[0.6rem] bg-primary-500 p-3 text-center text-base font-semibold text-white transition-colors duration-150 hover:enabled:bg-primary-700 disabled:cursor-default disabled:bg-[color:var(--color-muted)] ${FOCUS_RING}`}
        type="submit"
        disabled={submitting}
      >
        {submitting ? t['landing.login.submitting'] : t['landing.login.submit']}
      </button>

      <button
        type="button"
        className={`mx-auto mt-[0.9rem] block cursor-pointer border-0 bg-transparent p-0 text-center text-[0.82rem] font-semibold text-primary-700 ${FOCUS_RING}`}
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.forgot']}
      </button>

      <div className="my-4 flex items-center gap-3 text-[0.78rem] text-[color:var(--color-muted)] before:h-px before:flex-1 before:bg-[color:var(--color-border)] before:content-[''] after:h-px after:flex-1 after:bg-[color:var(--color-border)] after:content-['']">
        <span>{t['landing.login.or']}</span>
      </div>

      <button
        type="button"
        className={`block w-full cursor-pointer rounded-[0.6rem] border-[1.5px] border-primary-500 bg-transparent py-[0.7rem] text-[0.92rem] font-semibold text-primary-700 hover:bg-primary-50 ${FOCUS_RING}`}
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.needHelp']}
      </button>

      {showContactNote && (
        <p className="mt-[0.9rem] text-center text-[0.8rem] leading-[1.5] text-[color:var(--color-muted)]">
          {t['landing.login.contactNote']}
        </p>
      )}
    </form>
  )
}

export default function LandingLogin() {
  const { token } = useAuth()
  const [loggedInJustNow, setLoggedInJustNow] = useState(false)

  const isAuthenticated = Boolean(token) || loggedInJustNow

  return (
    <div className="overflow-x-hidden text-[color:var(--color-ink)]">
      <section className="mx-auto flex max-w-[76rem] flex-col gap-10 px-5 pt-8 pb-12 lg:flex-row lg:items-center lg:pt-12 lg:pb-16" id="top">
        <div className="lg:order-2 lg:flex-1" id="login">
          {isAuthenticated ? (
            <div
              className={`relative mx-auto max-w-[26rem] rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 py-10 text-center shadow-[0_18px_40px_-24px_rgba(15,107,76,0.35)] ${LOGIN_CARD_TOP_BAR}`}
            >
              <p className="m-0 text-[1.2rem] font-semibold font-display">{t['landing.login.alreadyTitle']}</p>
              <Link
                className="mt-4 block w-full rounded-[0.6rem] bg-primary-500 p-3 text-center text-base font-semibold text-white hover:bg-primary-700"
                to="/"
              >
                {t['landing.login.alreadyCta']}
              </Link>
            </div>
          ) : (
            <LoginCard onSuccess={() => setLoggedInJustNow(true)} />
          )}
        </div>

        <div className="lg:order-1 lg:flex-[1.2] lg:text-left">
          <h1 className="text-center text-[clamp(1.75rem,6vw,2.6rem)] font-bold leading-[1.22] font-display lg:text-left">
            {t['landing.hero.heading1']}
            <br />
            {t['landing.hero.heading2']}
          </h1>
          <p className="mx-auto mt-4 max-w-[34ch] text-center text-[1.02rem] leading-[1.7] text-[color:var(--color-muted)] lg:ml-0 lg:text-left">
            {t['landing.hero.sub1']}
            <br />
            {t['landing.hero.sub2']}
          </p>

          <ul className="mt-6 flex list-none flex-wrap justify-center gap-x-5 gap-y-3 p-0 lg:justify-start">
            {HERO_BADGES.map((badge) => (
              <li key={badge.label} className="flex items-center gap-2 text-[0.88rem] font-medium text-[color:var(--color-ink)]">
                <span
                  className={`inline-flex h-[2.1rem] w-[2.1rem] items-center justify-center rounded-[0.55rem] ${TONE_STYLES[badge.tone].soft}`}
                >
                  <Icon name={badge.icon} />
                </span>
                {badge.label}
              </li>
            ))}
          </ul>

          <form
            className="mx-auto mt-7 flex max-w-[30rem] flex-col gap-[0.6rem] md:flex-row lg:ml-0"
            onSubmit={(event) => {
              event.preventDefault()
              document.getElementById('classes')?.scrollIntoView({ behavior: 'smooth' })
            }}
          >
            <label className="sr-only" htmlFor="hero-filter-class">
              {t['landing.hero.filterClassLabel']}
            </label>
            <select
              id="hero-filter-class"
              defaultValue=""
              className={`rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-[0.9rem] text-[color:var(--color-ink)] md:flex-1 ${FOCUS_RING}`}
            >
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
            <select
              id="hero-filter-subject"
              defaultValue=""
              className={`rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-[0.9rem] text-[color:var(--color-ink)] md:flex-1 ${FOCUS_RING}`}
            >
              <option value="" disabled>
                {t['landing.hero.filterSubjectAll']}
              </option>
              {SUBJECT_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>

            <button
              type="submit"
              className={`cursor-pointer rounded-[0.6rem] bg-primary-500 px-[0.8rem] py-[0.65rem] text-[0.9rem] font-semibold text-white hover:bg-primary-700 ${FOCUS_RING}`}
            >
              {t['landing.hero.filterSubmit']}
            </button>
          </form>

          <figure className="mx-auto mt-8 text-center lg:ml-0 lg:text-left">
            <HeroIllustration className="mx-auto block w-[min(240px,60vw)] lg:ml-0" />
            <figcaption className="mt-2 text-[0.82rem] leading-[1.5] font-semibold text-primary-700">
              {t['landing.hero.illustrationCaption1']}
              <br />
              {t['landing.hero.illustrationCaption2']}
            </figcaption>
          </figure>
        </div>
      </section>

      <section className="bg-primary-50 px-5 py-12" id="features">
        <h2 className="mb-7 text-center text-[1.4rem] font-display md:text-left">
          {t['landing.features.heading']}
        </h2>
        <div className="mx-auto grid max-w-[64rem] grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-4">
          {FEATURES.map((feature) => (
            <div className="rounded-2xl bg-white px-[1.1rem] py-6 text-center shadow-[0_14px_30px_-24px_rgba(20,36,32,0.25)]" key={feature.title}>
              <span
                className={`mb-[0.9rem] inline-flex h-12 w-12 items-center justify-center rounded-full ${TONE_STYLES[feature.tone].soft}`}
              >
                <Icon name={feature.icon} />
              </span>
              <p className="m-0 font-semibold">{feature.title}</p>
              <p className="mt-[0.35rem] text-[0.85rem] text-[color:var(--color-muted)]">{feature.desc}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="mx-auto flex max-w-[76rem] flex-col gap-8 px-5 py-12 lg:flex-row lg:items-center" id="classes">
        <div className="lg:flex-[0_0_18rem]">
          <h2 className="text-left text-[1.6rem] font-display">{t['landing.classes.heading']}</h2>
          <p className="mt-3 max-w-[34ch] text-[color:var(--color-muted)]">{t['landing.classes.body']}</p>
          <a
            className="mt-5 inline-block rounded-[0.6rem] bg-primary-500 px-[1.4rem] py-[0.65rem] text-[0.9rem] font-semibold text-white"
            href="#login"
          >
            {t['landing.cta.button']}
          </a>
        </div>
        <div className="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-4 lg:flex-1">
          {CLASS_LEVELS.map((level) => (
            <div className={`rounded-2xl px-[1.1rem] py-6 text-white ${TONE_STYLES[level.tone].solid}`} key={level.label}>
              <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.22]">
                <Icon name={level.icon} />
              </span>
              <p className="mt-[0.9rem] mb-[0.1rem] text-[1.1rem] font-semibold font-display">{level.label}</p>
              <p className="mb-4 text-[0.8rem] opacity-[0.85]">{level.range}</p>
              <a className="inline-block text-[0.85rem] font-semibold underline underline-offset-[3px]" href="#login">
                {t['landing.classes.cta']}
              </a>
            </div>
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-[64rem] px-5 py-12 text-center" id="subjects">
        <h2 className="mb-7 text-[1.4rem] font-display">{t['landing.subjects.heading']}</h2>
        <ul className="grid list-none grid-cols-[repeat(auto-fit,minmax(5.5rem,1fr))] gap-5 p-0">
          {SUBJECTS.map((subject) => (
            <li key={subject.label} className="flex flex-col items-center gap-2 text-[0.82rem] text-[color:var(--color-ink)]">
              <span
                className={`inline-flex h-[3.25rem] w-[3.25rem] items-center justify-center rounded-full text-[0.85rem] font-semibold font-display ${TONE_STYLES[subject.tone].soft}`}
              >
                {subject.glyph}
              </span>
              {subject.label}
            </li>
          ))}
        </ul>
      </section>

      <section className="mx-auto grid max-w-[76rem] grid-cols-1 gap-5 px-5 pt-4 pb-12 lg:grid-cols-3">
        <div className="rounded-[1.1rem] bg-primary-700 px-6 py-8 text-center text-white">
          <HeroIllustration className="mx-auto mb-4 w-[120px]" />
          <h2 className="text-[1.3rem] text-white font-display">{t['landing.cta.heading']}</h2>
          <p className="mt-[0.6rem] text-[0.9rem] text-[#d9ece4]">{t['landing.cta.body']}</p>
          <a
            className="mt-5 inline-block rounded-[0.6rem] border-[1.5px] border-white/60 px-[1.4rem] py-[0.65rem] text-[0.9rem] font-semibold text-white"
            href="#login"
          >
            {t['landing.cta.button']}
          </a>
        </div>

        <div className="rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 py-8">
          <h2 className="mb-7 text-center text-[1.4rem] font-display">{t['landing.stats.heading']}</h2>
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="flex flex-col gap-1">
              <strong className="text-[1.6rem] text-primary-700 font-display">{t['landing.stats.classesValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.classesLabel']}</span>
            </div>
            <div className="flex flex-col gap-1">
              <strong className="text-[1.6rem] text-primary-700 font-display">{t['landing.stats.subjectsValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.subjectsLabel']}</span>
            </div>
            <div className="flex flex-col gap-1">
              <strong className="text-[1.6rem] text-primary-700 font-display">{t['landing.stats.portalsValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.portalsLabel']}</span>
            </div>
          </div>
        </div>

        <blockquote className="m-0 rounded-[1.1rem] bg-primary-50 px-6 py-8">
          <p className="m-0 text-base leading-[1.7] text-[color:var(--color-ink)]">&ldquo;{t['landing.quote.text']}&rdquo;</p>
          <cite className="mt-4 block text-[0.85rem] font-semibold text-primary-700 not-italic">— {t['landing.quote.author']}</cite>
        </blockquote>
      </section>

      <section className="mx-auto grid max-w-[76rem] grid-cols-1 gap-5 px-5 pt-4 pb-12 lg:grid-cols-[1fr_1.4fr_1fr] lg:items-start">
        <div className="rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 py-8" id="help">
          <h2 className="mb-7 text-left text-[1.4rem] font-display">{t['landing.onboarding.heading']}</h2>
          <ol className="m-0 flex list-none flex-col gap-3 pl-[1.2rem] text-[0.9rem] text-[color:var(--color-muted)]">
            <li>{t['landing.onboarding.step1']}</li>
            <li>{t['landing.onboarding.step2']}</li>
            <li>{t['landing.onboarding.step3']}</li>
          </ol>
        </div>

        <div className="rounded-[1.1rem] bg-primary-50 px-6 py-8">
          <h2 className="mb-7 text-center text-[1.4rem] font-display">{t['landing.audience.heading']}</h2>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-4">
            <div className={`rounded-xl border-l-[3px] px-4 py-[1.1rem] ${TONE_STYLES.green.subtle}`}>
              <p className="mb-[0.6rem] font-semibold">{t['landing.audience.studentTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.studentItem1']}</li>
                <li>{t['landing.audience.studentItem2']}</li>
                <li>{t['landing.audience.studentItem3']}</li>
              </ul>
            </div>
            <div className={`rounded-xl border-l-[3px] px-4 py-[1.1rem] ${TONE_STYLES.blue.subtle}`}>
              <p className="mb-[0.6rem] font-semibold">{t['landing.audience.teacherTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.teacherItem1']}</li>
                <li>{t['landing.audience.teacherItem2']}</li>
                <li>{t['landing.audience.teacherItem3']}</li>
              </ul>
            </div>
            <div className={`rounded-xl border-l-[3px] px-4 py-[1.1rem] ${TONE_STYLES.rose.subtle}`}>
              <p className="mb-[0.6rem] font-semibold">{t['landing.audience.guardianTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.guardianItem1']}</li>
                <li>{t['landing.audience.guardianItem2']}</li>
                <li>{t['landing.audience.guardianItem3']}</li>
              </ul>
            </div>
          </div>
        </div>

        <div className="rounded-[1.1rem] bg-primary-700 px-6 py-8 text-center text-white">
          <span className="inline-flex h-10 w-10 items-center justify-center text-[color:var(--color-accent)]">
            <Icon name="classRange" />
          </span>
          <h2 className="mt-[0.9rem] text-[1.15rem] text-white font-display">{t['landing.closing.heading']}</h2>
          <p className="mt-2 text-[0.85rem] text-[#d9ece4]">{t['landing.closing.body']}</p>
        </div>
      </section>

      <footer className="mx-auto flex max-w-[76rem] flex-col gap-8 border-t border-[color:var(--color-border)] px-5 pt-10 pb-8 md:flex-row md:flex-wrap md:justify-between">
        <div className="flex items-start gap-3">
          <span className="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-[0.65rem] bg-primary-500 text-white" aria-hidden="true">
            <Icon name="logo" />
          </span>
          <div>
            <p className="m-0 font-semibold">{t['landing.brandFull']}</p>
            <p className="mt-1 text-[0.85rem] text-[color:var(--color-muted)]">{t['landing.footer.tagline']}</p>
          </div>
        </div>

        <nav className="flex flex-wrap items-center gap-4 text-[0.85rem] text-[color:var(--color-muted)]" aria-label="এক নজরে">
          <p className="m-0 w-full font-semibold text-[color:var(--color-ink)]">{t['landing.footer.linksHeading']}</p>
          {NAV_LINKS.map((link) => (
            <a key={link.href} href={link.href}>
              {link.label}
            </a>
          ))}
        </nav>

        <div>
          <p className="m-0 font-semibold">{t['landing.footer.helpHeading']}</p>
          <p className="mt-[0.4rem] max-w-[40ch] text-[0.85rem] text-[color:var(--color-muted)]">{t['landing.footer.helpBody']}</p>
        </div>

        <p className="m-0 w-full border-t border-[color:var(--color-border)] pt-6 text-center text-[0.78rem] text-[color:var(--color-muted)]">
          © {currentYear} {t['landing.brand']} — {t['landing.footer.copyright']}
        </p>
      </footer>
    </div>
  )
}
