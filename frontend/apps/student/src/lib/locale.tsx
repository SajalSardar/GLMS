import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { getCommonStrings, type CommonStrings, type Locale } from '@ttla/i18n'

const LOCALE_STORAGE_KEY = 'ttla.studentLocale'

function isLocale(value: string | null): value is Locale {
  return value === 'en' || value === 'bn'
}

function getStoredLocale(): Locale {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
  return isLocale(stored) ? stored : 'bn'
}

function storeLocale(locale: Locale): void {
  localStorage.setItem(LOCALE_STORAGE_KEY, locale)
}

export interface LocaleContextValue {
  locale: Locale
  setLocale: (locale: Locale) => void
  t: CommonStrings
}

const LocaleContext = createContext<LocaleContextValue | null>(null)

export function LocaleProvider({ children }: { children: ReactNode }) {
  // Default is 'bn' (not getCommonStrings()'s own 'en' default) to match
  // this app's pre-existing behavior: every page hardcoded getCommonStrings('bn')
  // before this provider existed.
  const [locale, setLocaleState] = useState<Locale>(() => getStoredLocale())

  const setLocale = useCallback((next: Locale) => {
    storeLocale(next)
    setLocaleState(next)
  }, [])

  const value = useMemo<LocaleContextValue>(
    () => ({ locale, setLocale, t: getCommonStrings(locale) }),
    [locale, setLocale],
  )

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export function useLocale(): LocaleContextValue {
  const context = useContext(LocaleContext)

  if (!context) {
    throw new Error('useLocale must be used within a LocaleProvider')
  }

  return context
}
