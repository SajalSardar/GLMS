import en from './common/en.json'
import bn from './common/bn.json'

export type Locale = 'en' | 'bn'
export type CommonStrings = typeof en

const commonStrings: Record<Locale, CommonStrings> = { en, bn }

export function getCommonStrings(locale: Locale = 'en'): CommonStrings {
  return commonStrings[locale]
}
