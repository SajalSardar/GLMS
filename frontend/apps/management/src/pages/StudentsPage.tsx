import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  deleteStudentUser,
  listClasses,
  listDistricts,
  listSchools,
  listStudentUsers,
  type District,
  type School,
  type SchoolClass,
  type StudentUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { type GeoColumn } from '../components/GeoCrudTable'
import { schoolLabel } from '../components/schoolOptions'

const t = getCommonStrings('en')

function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}

export default function StudentsPage() {
  const navigate = useNavigate()
  const { token } = useAuth()
  const [schools, setSchools] = useState<School[]>([])
  const [districts, setDistricts] = useState<District[]>([])
  const [classes, setClasses] = useState<SchoolClass[]>([])

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    listDistricts({ perPage: 200 }, token ?? undefined)
      .then((result) => setDistricts(result.data))
      .catch(() => setDistricts([]))

    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  const columns: GeoColumn<StudentUser>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    { key: 'email', label: t['management.fields.email'], render: (row) => row.email ?? '—' },
    { key: 'phone', label: t['management.fields.phone'], render: (row) => row.phone ?? '—' },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => row.class?.name ?? '—',
    },
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
    <GeoCrudTable<StudentUser>
      title={t['management.students.title']}
      columns={columns}
      fetchPage={(page, filters) =>
        listStudentUsers(
          {
            page,
            search: filters.search,
            schoolId: filters.parentId,
            districtId: filters.secondaryParentId,
            classId: filters.tertiaryParentId,
          },
          token ?? undefined,
        )
      }
      deleteRow={(id, authToken) => deleteStudentUser(id, authToken)}
      onAdd={() => navigate('/dashboard/students/new')}
      onView={(row) => navigate(`/dashboard/students/${row.id}`)}
      onEdit={(row) => navigate(`/dashboard/students/${row.id}?edit=1`)}
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
      tertiaryFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClassLabel(schoolClass) })),
      }}
    />
  )
}
