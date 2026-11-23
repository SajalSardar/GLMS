import { useEffect, useState, type ReactNode } from 'react'
import {
  createSubjectTeacherAssignment,
  deleteSubjectTeacherAssignment,
  listClasses,
  listClassVersions,
  listSchools,
  listSections,
  listSubjectTeacherAssignments,
  listTeacherUsers,
  updateSubjectTeacherAssignment,
  type ClassVersion,
  type School,
  type SchoolClass,
  type Section,
  type SubjectTeacherAssignment,
  type TeacherUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  school_id: '',
  section_id: '',
  subject_id: '',
  teacher_id: '',
}

const requiredFields = ['school_id', 'section_id', 'subject_id', 'teacher_id']

function sectionLabel(section: Section): string {
  const className = section.shift?.class_version?.class?.name
  const versionName = section.shift?.class_version?.name
  const shiftName = section.shift?.name

  const parts = [className, versionName, shiftName, section.name].filter(
    (part): part is string => Boolean(part),
  )

  return parts.length > 0 ? parts.join(' • ') : section.name
}

function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}

function schoolNameFor(schools: School[], schoolId: number): string {
  const school = schools.find((candidate) => candidate.id === schoolId)
  return school ? schoolLabel(school) : String(schoolId)
}

function toPayload(values: Record<string, string>) {
  return {
    section_id: Number(values.section_id),
    subject_id: Number(values.subject_id),
    teacher_id: Number(values.teacher_id),
  }
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-right text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function AssignmentDetails({
  assignment,
  schools,
}: {
  assignment: SubjectTeacherAssignment
  schools: School[]
}) {
  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.school']} value={schoolNameFor(schools, assignment.school_id)} />
      <DetailRow
        label={t['management.fields.section']}
        value={assignment.section ? sectionLabel(assignment.section) : assignment.section_id}
      />
      <DetailRow label={t['management.fields.subject']} value={assignment.subject?.name ?? assignment.subject_id} />
      <DetailRow label={t['management.fields.teacher']} value={assignment.teacher?.name ?? assignment.teacher_id} />
    </div>
  )
}

export default function SubjectTeacherAssignmentsPage() {
  const { token } = useAuth()
  const [sections, setSections] = useState<Section[]>([])
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [teachers, setTeachers] = useState<TeacherUser[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classes, setClasses] = useState<SchoolClass[]>([])

  useEffect(() => {
    listSections({ perPage: 200 })
      .then((result) => setSections(result.data))
      .catch(() => setSections([]))

    listClassVersions({ perPage: 200 })
      .then((result) => setClassVersions(result.data))
      .catch(() => setClassVersions([]))

    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    if (token) {
      listTeacherUsers({ perPage: 500 }, token)
        .then((result) => setTeachers(result.data))
        .catch(() => setTeachers([]))
    }
  }, [token])

  const columns: GeoColumn<SubjectTeacherAssignment>[] = [
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => schoolNameFor(schools, row.school_id),
    },
    {
      key: 'section',
      label: t['management.fields.section'],
      render: (row) => (row.section ? sectionLabel(row.section) : row.section_id),
    },
    {
      key: 'subject',
      label: t['management.fields.subject'],
      render: (row) => row.subject?.name ?? row.subject_id,
    },
    {
      key: 'teacher',
      label: t['management.fields.teacher'],
      render: (row) => row.teacher?.name ?? row.teacher_id,
    },
  ]

  return (
    <GeoCrudTable<SubjectTeacherAssignment>
      title={t['management.subjectTeacherAssignments.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_id: String(row.school_id),
        section_id: String(row.section_id),
        subject_id: String(row.subject_id),
        teacher_id: String(row.teacher_id),
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => {
        const selectedSection = sections.find((section) => String(section.id) === values.section_id)
        const selectedClassVersion = selectedSection
          ? classVersions.find((version) => version.id === selectedSection.shift?.class_version_id)
          : undefined

        const sectionOptionsForSchool = sections
          .filter((section) => String(section.school_id) === values.school_id)
          .map((section) => ({ value: String(section.id), label: sectionLabel(section) }))

        const subjectOptions = (selectedClassVersion?.subjects ?? []).map((subject) => ({
          value: String(subject.id),
          label: subject.name,
        }))

        // Scoped by the chosen School directly (not the chosen Section) —
        // a teacher belongs to exactly one school regardless of which of
        // that school's sections ends up selected, so this stays correct
        // the moment School is picked, before Section is touched at all.
        const teacherOptions = teachers
          .filter((teacher) => !values.school_id || teacher.school?.id === Number(values.school_id))
          .map((teacher) => ({ value: String(teacher.id), label: teacher.name }))

        // school_id/section_id/subject_id are immutable after creation (the
        // backend's UpdateSubjectTeacherAssignmentRequest only accepts
        // teacher_id) — shown as plain text rather than an editable picker
        // during edit, so the form never implies a change that would
        // silently be dropped.
        const isEditing = editingId !== null

        return (
          <>
            <GeoField label={t['management.fields.school']} required error={fieldErrors.school_id}>
              {isEditing ? (
                <p className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                  {schoolNameFor(schools, Number(values.school_id))}
                </p>
              ) : (
                <SearchableSelect
                  value={values.school_id}
                  onChange={(next) => {
                    onChange('school_id', next)
                    onChange('section_id', '')
                    onChange('subject_id', '')
                    onChange('teacher_id', '')
                  }}
                  placeholder={t['management.fields.school']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))}
                />
              )}
            </GeoField>

            <GeoField label={t['management.fields.section']} required error={fieldErrors.section_id}>
              {isEditing ? (
                <p className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                  {selectedSection ? sectionLabel(selectedSection) : values.section_id}
                </p>
              ) : (
                <SearchableSelect
                  value={values.section_id}
                  onChange={(next) => {
                    onChange('section_id', next)
                    onChange('subject_id', '')
                    onChange('teacher_id', '')
                  }}
                  placeholder={t['management.fields.section']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={sectionOptionsForSchool}
                  className={!values.school_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
                />
              )}
            </GeoField>

            <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
              {isEditing ? (
                <p className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                  {selectedClassVersion?.subjects?.find((subject) => String(subject.id) === values.subject_id)
                    ?.name ?? values.subject_id}
                </p>
              ) : (
                <SearchableSelect
                  value={values.subject_id}
                  onChange={(next) => onChange('subject_id', next)}
                  placeholder={t['management.fields.subject']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={subjectOptions}
                  className={!selectedSection ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
                />
              )}
            </GeoField>

            <GeoField label={t['management.fields.teacher']} required error={fieldErrors.teacher_id}>
              <SearchableSelect
                value={values.teacher_id}
                onChange={(next) => onChange('teacher_id', next)}
                placeholder={t['management.fields.teacher']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={teacherOptions}
                className={!isEditing && !values.school_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
              />
            </GeoField>
          </>
        )
      }}
      fetchPage={(page, filters) =>
        listSubjectTeacherAssignments({
          page,
          schoolId: filters.parentId,
          classId: filters.secondaryParentId,
          sectionId: filters.tertiaryParentId,
        }, token ?? undefined)
      }
      createRow={(values, token) => createSubjectTeacherAssignment(toPayload(values), token)}
      updateRow={(id, values, token) => updateSubjectTeacherAssignment(id, { teacher_id: Number(values.teacher_id) }, token)}
      deleteRow={(id, token) => deleteSubjectTeacherAssignment(id, token)}
      renderDetails={(row) => <AssignmentDetails assignment={row} schools={schools} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
      secondaryFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClassLabel(schoolClass) })),
      }}
      tertiaryFilter={{
        label: t['management.fields.section'],
        allLabel: t['management.fields.allSections'],
        options: sections.map((section) => ({ id: section.id, label: sectionLabel(section) })),
      }}
    />
  )
}
