import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  deleteTeacherUser,
  listDistricts,
  listSchools,
  listTeacherUsers,
  type District,
  type School,
  type TeacherUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { type GeoColumn } from '../components/GeoCrudTable'
import { schoolLabel } from '../components/schoolOptions'

const t = getCommonStrings('en')

export default function TeachersPage() {
  const navigate = useNavigate()
  const { token, can } = useAuth()
  const [schools, setSchools] = useState<School[]>([])
  const [districts, setDistricts] = useState<District[]>([])

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    listDistricts({ perPage: 200 }, token ?? undefined)
      .then((result) => setDistricts(result.data))
      .catch(() => setDistricts([]))
  }, [])

  const columns: GeoColumn<TeacherUser>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    { key: 'email', label: t['management.fields.email'], render: (row) => row.email ?? '—' },
    { key: 'phone', label: t['management.fields.phone'], render: (row) => row.phone ?? '—' },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => row.school?.name ?? '—',
    },
    {
      key: 'district',
      label: t['management.fields.district'],
      render: (row) => row.district?.name ?? '—',
    },
  ]

  return (
    <GeoCrudTable<TeacherUser>
      title={t['management.teachers.title']}
      columns={columns}
      fetchPage={(page, filters) =>
        listTeacherUsers(
          { page, search: filters.search, schoolId: filters.parentId, districtId: filters.secondaryParentId },
          token ?? undefined,
        )
      }
      deleteRow={(id, authToken) => deleteTeacherUser(id, authToken)}
      onAdd={() => navigate('/dashboard/teachers/new')}
      onView={(row) => navigate(`/dashboard/teachers/${row.id}`)}
      onEdit={(row) => navigate(`/dashboard/teachers/${row.id}?edit=1`)}
      rowActions={(row) =>
        can('teacher_profiles.print') ? (
          <button
            type="button"
            className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
            onClick={() => navigate(`/dashboard/teachers/${row.id}/id-card`)}
            aria-label={t['management.table.print']}
            title={t['management.table.print']}
          >
            <span className="material-symbols-outlined !text-md">print</span>
          </button>
        ) : null
      }
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
      secondaryFilter={{
        label: t['management.fields.district'],
        allLabel: t['management.fields.allDistricts'],
        options: districts.map((district) => ({ id: district.id, label: district.name })),
      }}
    />
  )
}
