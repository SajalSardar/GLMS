import { useId, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { studentLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

type IconName = 'eyeOpen' | 'eyeClosed'

const HERO_BADGES: Array<{ image: string; label: string }> = [
  { image: '/f-video.png', label: t['landing.hero.badgeVideo'] },
  { image: '/f-study.png', label: t['landing.hero.badgeMaterial'] },
  { image: '/f-exam.png', label: t['landing.hero.badgeExam'] },
  { image: '/f-progress.png', label: t['landing.hero.badgeProgress'] },
]

const FEATURES: Array<{ image: string; title: string; desc: string }> = [
  { image: '/card-1.png', title: t['landing.features.classRangeTitle'], desc: t['landing.features.classRangeDesc'] },
  { image: '/card-2.png', title: t['landing.features.videoTitle'], desc: t['landing.features.videoDesc'] },
  { image: '/card-3.png', title: t['landing.features.materialTitle'], desc: t['landing.features.materialDesc'] },
  { image: '/card-4.png', title: t['landing.features.examTitle'], desc: t['landing.features.examDesc'] },
  { image: '/card-5.png', title: t['landing.features.progressTitle'], desc: t['landing.features.progressDesc'] },
  { image: '/card-6.png', title: t['landing.features.anywhereTitle'], desc: t['landing.features.anywhereDesc'] },
]

// Gradient backgrounds copied from the reference design's four class-level
// cards (one per level, not a reusable tone — these exact gradients appear
// nowhere else on the page).
const CLASS_LEVELS: Array<{ gradient: string; pillText: string; image: string; label: string; range: string }> = [
  {
    gradient: 'bg-gradient-to-b from-[#43B85C] to-[#7FD38A]',
    pillText: 'text-[#14833B]',
    image: '/kid-1.png',
    label: t['landing.classes.primary'],
    range: t['landing.classes.primaryRange'],
  },
  {
    gradient: 'bg-gradient-to-b from-[#2E8DE6] to-[#8CC8F5]',
    pillText: 'text-[#1C6FC0]',
    image: '/kid-2.png',
    label: t['landing.classes.secondary'],
    range: t['landing.classes.secondaryRange'],
  },
  {
    gradient: 'bg-gradient-to-b from-[#F7941D] to-[#FCC77A]',
    pillText: 'text-[#D86F00]',
    image: '/kid-3.png',
    label: t['landing.classes.ssc'],
    range: t['landing.classes.sscRange'],
  },
  {
    gradient: 'bg-gradient-to-b from-[#9B4FD8] to-[#CFA6F0]',
    pillText: 'text-[#7A3BB5]',
    image: '/kid-4.png',
    label: t['landing.classes.hsc'],
    range: t['landing.classes.hscRange'],
  },
]

const SUBJECTS: Array<{ image: string; label: string }> = [
  { image: '/sub-1.png', label: t['landing.subjects.math'] },
  { image: '/sub-2.png', label: t['landing.subjects.bangla'] },
  { image: '/sub-3.png', label: t['landing.subjects.english'] },
  { image: '/sub-4.png', label: t['landing.subjects.science'] },
  { image: '/sub-5.png', label: t['landing.subjects.ict'] },
  { image: '/sub-6.png', label: t['landing.subjects.social'] },
  { image: '/sub-7.png', label: t['landing.subjects.religion'] },
  { image: '/sub-8.png', label: t['landing.subjects.agriculture'] },
  { image: '/sub-9.png', label: t['landing.subjects.health'] },
  { image: '/sub-10.png', label: t['landing.subjects.other'] },
]

const CLASS_OPTIONS = CLASS_LEVELS.map((level) => `${level.label} (${level.range})`)
const SUBJECT_OPTIONS = SUBJECTS.map((subject) => subject.label)

// Tone-color utility classes for the audience cards' left-border tint —
// the only remaining consumer now that the icon badges/cards/levels/
// subjects above use real image icons instead of tone-colored chips.
// Written out as literal strings (not built from template pieces) so
// Tailwind's build-time class scanner can see every one of them.
const TONE_STYLES: Record<string, { subtle: string }> = {
  green: {
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-green)_12%,white)] border-[color:var(--color-tone-green)]',
  },
  blue: {
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-blue)_12%,white)] border-[color:var(--color-tone-blue)]',
  },
  rose: {
    subtle: 'bg-[color:color-mix(in_srgb,var(--color-tone-rose)_12%,white)] border-[color:var(--color-tone-rose)]',
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
                to="/books"
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

          <ul className="mt-6 grid list-none grid-cols-2 justify-items-center gap-x-6 gap-y-4 p-0 sm:grid-cols-4 lg:justify-items-start">
            {HERO_BADGES.map((badge) => (
              <li key={badge.label} className="flex flex-col items-center gap-1 text-[0.8rem] font-medium text-[color:var(--color-ink)]">
                <img src={badge.image} alt="" className="h-12 w-12 object-contain" />
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
        </div>
      </section>

      <section className="bg-primary-50 px-5 py-12" id="features">
        <div className="mx-auto grid max-w-[64rem] grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-4">
          {FEATURES.map((feature) => (
            <div className="rounded-2xl bg-white px-[1.1rem] py-6 text-center shadow-[0_14px_30px_-24px_rgba(20,36,32,0.25)]" key={feature.title}>
              <img src={feature.image} alt="" className="mx-auto mb-[0.9rem] h-16 w-16 object-contain" />
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
            <a
              href="#login"
              className={`block rounded-2xl px-[1.1rem] py-5 text-center text-white transition hover:-translate-y-1 ${level.gradient}`}
              key={level.label}
            >
              <p className="text-[1.1rem] font-semibold font-display">{level.label}</p>
              <p className="text-[0.8rem] opacity-[0.85]">{level.range}</p>
              <img src={level.image} alt="" className="mx-auto mt-2 h-[4.6rem] rounded object-contain" />
              <span className={`mt-2 block rounded-full bg-white py-1.5 text-[0.78rem] font-semibold ${level.pillText}`}>
                {t['landing.classes.cta']}
              </span>
            </a>
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-[64rem] px-5 py-12 text-center" id="subjects">
        <h2 className="mb-7 text-[1.4rem] font-display">{t['landing.subjects.heading']}</h2>
        <ul className="grid list-none grid-cols-3 gap-2 p-0 sm:grid-cols-5 lg:grid-cols-10">
          {SUBJECTS.map((subject) => (
            <li
              key={subject.label}
              className="rounded-lg border border-[color:var(--color-border)] bg-[color:var(--color-primary-tint)]/40 py-3 text-center text-[0.78rem] font-medium text-[color:var(--color-ink)] transition hover:border-primary-500"
            >
              <img src={subject.image} alt="" className="mx-auto h-12 w-12 object-contain" />
              <p className="mt-1">{subject.label}</p>
            </li>
          ))}
        </ul>
      </section>

      <section className="mx-auto grid max-w-[76rem] grid-cols-1 gap-5 px-5 pt-4 pb-12 lg:grid-cols-3">
        <div className="flex overflow-hidden rounded-[1.1rem] bg-primary-50">
          <img src="/banner.png" alt="" className="w-[42%] object-cover" />
          <div className="self-center px-4 py-6">
            <h2 className="text-[1.3rem] leading-snug font-display">{t['landing.cta.heading']}</h2>
            <p className="mt-2 text-[0.9rem] font-medium text-[color:var(--color-muted)]">{t['landing.cta.body']}</p>
            <a
              className="mt-3 inline-block rounded-full bg-white px-4 py-1.5 text-[0.85rem] font-semibold text-primary-700 shadow-sm hover:shadow"
              href="#login"
            >
              {t['landing.cta.button']}
            </a>
          </div>
        </div>

        <div className="rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 py-8">
          <h2 className="mb-7 text-center text-[1.4rem] font-display">{t['landing.stats.heading']}</h2>
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="flex flex-col items-center gap-1">
              <img src="/stat-1.png" alt="" className="h-8 w-8" />
              <strong className="mt-2 text-[1.6rem] text-primary-700 font-display">{t['landing.stats.classesValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.classesLabel']}</span>
            </div>
            <div className="flex flex-col items-center gap-1">
              <img src="/stat-2.png" alt="" className="h-8 w-8" />
              <strong className="mt-2 text-[1.6rem] text-primary-700 font-display">{t['landing.stats.subjectsValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.subjectsLabel']}</span>
            </div>
            <div className="flex flex-col items-center gap-1">
              <img src="/stat-3.png" alt="" className="h-8 w-8" />
              <strong className="mt-2 text-[1.6rem] text-primary-700 font-display">{t['landing.stats.portalsValue']}</strong>
              <span className="text-[0.75rem] text-[color:var(--color-muted)]">{t['landing.stats.portalsLabel']}</span>
            </div>
          </div>
        </div>

        <blockquote className="m-0 rounded-[1.1rem] bg-primary-50 px-6 py-8">
          <p className="m-0 text-base leading-[1.7] text-[color:var(--color-ink)]">&ldquo;{t['landing.quote.text']}&rdquo;</p>
          <div className="mt-4 flex items-center justify-between">
            <cite className="block text-[0.85rem] font-semibold text-primary-700 not-italic">— {t['landing.quote.author']}</cite>
            <img src="/avatar.png" alt="" className="h-12 w-12 rounded-full object-cover ring-4 ring-white" />
          </div>
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
              <img src="/aud-1.png" alt="" className="mx-auto h-8 w-8" />
              <p className="mt-2 mb-[0.6rem] text-center font-semibold">{t['landing.audience.studentTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.studentItem1']}</li>
                <li>{t['landing.audience.studentItem2']}</li>
                <li>{t['landing.audience.studentItem3']}</li>
              </ul>
            </div>
            <div className={`rounded-xl border-l-[3px] px-4 py-[1.1rem] ${TONE_STYLES.blue.subtle}`}>
              <img src="/aud-2.png" alt="" className="mx-auto h-8 w-8" />
              <p className="mt-2 mb-[0.6rem] text-center font-semibold">{t['landing.audience.teacherTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.teacherItem1']}</li>
                <li>{t['landing.audience.teacherItem2']}</li>
                <li>{t['landing.audience.teacherItem3']}</li>
              </ul>
            </div>
            <div className={`rounded-xl border-l-[3px] px-4 py-[1.1rem] ${TONE_STYLES.rose.subtle}`}>
              <img src="/aud-3.png" alt="" className="mx-auto h-8 w-8" />
              <p className="mt-2 mb-[0.6rem] text-center font-semibold">{t['landing.audience.guardianTitle']}</p>
              <ul className="m-0 flex list-none flex-col gap-[0.3rem] pl-[1.1rem] text-[0.82rem] text-[color:var(--color-muted)]">
                <li>{t['landing.audience.guardianItem1']}</li>
                <li>{t['landing.audience.guardianItem2']}</li>
                <li>{t['landing.audience.guardianItem3']}</li>
              </ul>
            </div>
          </div>
        </div>

        <div className="rounded-[1.1rem] bg-primary-50 px-6 py-8 text-center">
          {/* Both images have a near-white background baked in, not a
              transparent one — multiply-blending them onto this card's
              light tint hides that background instead of showing it as a
              mismatched box. */}
          <img src="/tree.png" alt="" className="mx-auto h-14 object-contain mix-blend-multiply" />
          <h2 className="mt-2 text-[1.15rem] font-display">{t['landing.closing.heading']}</h2>
          <p className="mt-2 text-[0.85rem] text-[color:var(--color-muted)]">{t['landing.closing.body']}</p>
          <img src="/book.png" alt="" className="mx-auto mt-1 h-8 object-contain mix-blend-multiply" />
        </div>
      </section>
    </div>
  )
}
