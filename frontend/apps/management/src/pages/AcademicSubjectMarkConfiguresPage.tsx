import { useEffect, useState } from 'react'
import {
  createAcademicSubjectMarkConfigure,
  deleteAcademicSubjectMarkConfigure,
  listAcademicSubjectMarkConfigures,
  listClasses,
  listSubjects,
  updateAcademicSubjectMarkConfigure,
  type AcademicSubjectMarkConfigure,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  class_id: '',
  subject_id: '',
  cq_full_marks: '',
  mcq_full_marks: '',
  practical_full_marks: '',
}

const requiredFields = ['class_id', 'subject_id']

function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
    subject_id: Number(values.subject_id),
    cq_full_marks: values.cq_full_marks ? Number(values.cq_full_marks) : null,
    mcq_full_marks: values.mcq_full_marks ? Number(values.mcq_full_marks) : null,
    practical_full_marks: values.practical_full_marks ? Number(values.practical_full_marks) : null,
  }
}

export default function AcademicSubjectMarkConfiguresPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])

  useEffect(() => {
    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  const columns: GeoColumn<AcademicSubjectMarkConfigure>[] = [
    { key: 'class', label: t['management.fields.class'], render: (row) => row.class?.bn_name ?? row.class?.name ?? row.class_id },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.subject?.bn_name ?? row.subject?.name ?? row.subject_id },
    { key: 'cq', label: t['management.fields.cqFullMarks'], render: (row) => row.cq_full_marks ?? '—' },
    { key: 'mcq', label: t['management.fields.mcqFullMarks'], render: (row) => row.mcq_full_marks ?? '—' },
    { key: 'practical', label: t['management.fields.practicalFullMarks'], render: (row) => row.practical_full_marks ?? '—' },
  ]

  return (
    <GeoCrudTable<AcademicSubjectMarkConfigure>
      title={t['management.academicSubjectMarkConfigures.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        class_id: String(row.class_id),
        subject_id: String(row.subject_id),
        cq_full_marks: row.cq_full_marks !== null ? String(row.cq_full_marks) : '',
        mcq_full_marks: row.mcq_full_marks !== null ? String(row.mcq_full_marks) : '',
        practical_full_marks: row.practical_full_marks !== null ? String(row.practical_full_marks) : '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
            <select className={inputClass} value={values.class_id} onChange={(event) => onChange('class_id', event.target.value)}>
              <option value="">—</option>
              {classes.map((schoolClass) => (
                <option key={schoolClass.id} value={schoolClass.id}>
                  {schoolClass.bn_name ?? schoolClass.name}
                </option>
              ))}
            </select>
          </GeoField>

          <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
            <select className={inputClass} value={values.subject_id} onChange={(event) => onChange('subject_id', event.target.value)}>
              <option value="">—</option>
              {subjects.map((subject) => (
                <option key={subject.id} value={subject.id}>
                  {subject.bn_name ?? subject.name}
                </option>
              ))}
            </select>
          </GeoField>

          <GeoField label={t['management.fields.cqFullMarks']} error={fieldErrors.cq_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.cq_full_marks}
              onChange={(event) => onChange('cq_full_marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.mcqFullMarks']} error={fieldErrors.mcq_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.mcq_full_marks}
              onChange={(event) => onChange('mcq_full_marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.practicalFullMarks']} error={fieldErrors.practical_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.practical_full_marks}
              onChange={(event) => onChange('practical_full_marks', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page) => listAcademicSubjectMarkConfigures({ page })}
      createRow={(values, token) => createAcademicSubjectMarkConfigure(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicSubjectMarkConfigure(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicSubjectMarkConfigure(id, token)}
      createPermission="academic_subject_mark_configures.create"
      updatePermission="academic_subject_mark_configures.update"
      deletePermission="academic_subject_mark_configures.delete"
    />
  )
}
