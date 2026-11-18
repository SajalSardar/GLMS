import type { School, SchoolLevel, SchoolType } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Same school name can recur across districts, so every school picker
// disambiguates by appending the district — consistent with the
// "X • Y" convention used for class/shift/section labels elsewhere.
export function schoolLabel(school: School): string {
  const districtName = school.district?.name
  return districtName ? `${school.name} • ${districtName}` : school.name
}

export const SCHOOL_TYPES: Array<{ value: SchoolType; label: string }> = [
  { value: 'government', label: t['management.schoolType.government'] },
  { value: 'non_government', label: t['management.schoolType.nonGovernment'] },
  { value: 'private', label: t['management.schoolType.private'] },
]

export const SCHOOL_LEVELS: Array<{ value: SchoolLevel; label: string }> = [
  { value: 'primary', label: t['management.schoolLevel.primary'] },
  { value: 'secondary', label: t['management.schoolLevel.secondary'] },
  { value: 'higher_secondary', label: t['management.schoolLevel.higherSecondary'] },
  { value: 'college', label: t['management.schoolLevel.college'] },
]
