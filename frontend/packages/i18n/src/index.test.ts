import { describe, expect, it } from 'vitest'
import { getCommonStrings } from './index'

describe('getCommonStrings', () => {
  it('defaults to English', () => {
    expect(getCommonStrings()['dashboard.statusOk']).toBe('Connected')
  })

  it('returns Bangla strings when requested', () => {
    expect(getCommonStrings('bn')['dashboard.statusOk']).toBe('সংযুক্ত')
  })
})
