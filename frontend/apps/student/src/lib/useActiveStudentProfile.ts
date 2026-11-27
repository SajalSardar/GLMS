import { useEffect, useState } from 'react'
import { useAuth } from '@ttla/auth'
import { ownStudentProfileId } from './resolveContent'
import { useGuardianChild } from './guardianChild'

export interface ActiveStudentProfile {
  // null while a student session's own profile id is still resolving, or
  // while a guardian session hasn't selected a child yet (shouldn't
  // normally happen past the /select-child redirect, but a page can still
  // render briefly before that redirect lands).
  studentProfileId: number | null
  isGuardian: boolean
}

// Which student profile a dashboard page's data should be scoped to — the
// signed-in student's own profile, or (for a guardian session) the child
// currently selected via the post-login picker. Every page that fetches
// "my" data should read this instead of @ttla/auth's userId directly,
// since for a guardian session userId is the GUARDIAN's own id, not a
// student's.
export function useActiveStudentProfile(): ActiveStudentProfile {
  const { role, userId, token } = useAuth()
  const { selectedChildId } = useGuardianChild()
  const isGuardian = role === 'parent_guardian'

  const [ownProfileId, setOwnProfileId] = useState<number | null>(null)

  useEffect(() => {
    if (isGuardian || !token || !userId) return

    let active = true

    ownStudentProfileId(userId, token).then((id) => {
      if (active) setOwnProfileId(id)
    })

    return () => {
      active = false
    }
  }, [isGuardian, token, userId])

  return { studentProfileId: isGuardian ? selectedChildId : ownProfileId, isGuardian }
}
