import type { SchoolLevel, SchoolType } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

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
