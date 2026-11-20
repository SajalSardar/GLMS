import { useEffect, useState } from 'react'
import { listHolidays, type Holiday } from '@ttla/api-client'
import { useLocale } from '../../lib/locale'
import Pagination from './components/Pagination'

const PAGE_SIZE = 10

function InfoAlert({ message }: { message: string }) {
  return (
    <div className="mt-6 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700">
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v5" />
        <circle cx="12" cy="8" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

// Dashboard-only page (no public website equivalent, same as Class
// Routine/Leave Application) — the national academic holiday calendar,
// read-only here: only a national_admin can create/update/delete it (see
// the management portal's Holiday Calendar page), every other actor
// (including a logged-in student) only ever views it.
export default function HolidayCalendarPage() {
  const { t } = useLocale()

  const [loading, setLoading] = useState(true)
  const [holidays, setHolidays] = useState<Holiday[]>([])
  const [page, setPage] = useState(1)

  useEffect(() => {
    let active = true
    setLoading(true)

    listHolidays({ perPage: 100, activeOnly: true })
      .then((result) => {
        if (active) setHolidays(result.data)
      })
      .catch(() => {
        if (active) setHolidays([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  function dayOrDateLabel(holiday: Holiday): string {
    if (holiday.type === 'weekly') {
      const labels: Record<string, string> = {
        saturday: t['management.days.saturday'],
        sunday: t['management.days.sunday'],
        monday: t['management.days.monday'],
        tuesday: t['management.days.tuesday'],
        wednesday: t['management.days.wednesday'],
        thursday: t['management.days.thursday'],
        friday: t['management.days.friday'],
      }
      return holiday.day_of_week ? (labels[holiday.day_of_week] ?? holiday.day_of_week) : '—'
    }

    if (!holiday.start_date || !holiday.end_date) return '—'
    return holiday.start_date === holiday.end_date ? holiday.start_date : `${holiday.start_date} – ${holiday.end_date}`
  }

  function typeLabel(holiday: Holiday): string {
    if (holiday.type === 'weekly') return t['management.fields.holidayTypeWeekly']
    if (holiday.type === 'government') return t['management.fields.holidayTypeGovernment']
    return t['management.fields.holidayTypeOther']
  }

  const totalPages = Math.max(1, Math.ceil(holidays.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleHolidays = holidays.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.holidays.title']}</h1>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.holidays.loading']}</p>}

      {!loading && holidays.length === 0 && <InfoAlert message={t['student.holidays.empty']} />}

      {!loading && holidays.length > 0 && (
        <>
          <div className="mt-6 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[32rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.holidays.colDay']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.holidays.colName']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.holidays.colType']}</th>
                </tr>
              </thead>
              <tbody>
                {visibleHolidays.map((holiday, index) => (
                  <tr key={holiday.id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {(currentPage - 1) * PAGE_SIZE + index + 1}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{dayOrDateLabel(holiday)}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <span className="font-medium text-primary-900">{holiday.bn_name ?? holiday.name}</span>
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{typeLabel(holiday)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={(targetPage) => setPage(targetPage)}
            visibleCount={visibleHolidays.length}
            total={holidays.length}
            showingLabel={t['management.table.showing']}
            ofLabel={t['management.table.of']}
            resultsLabel={t['management.table.results']}
            previousLabel={t['student.holidays.previousPage']}
            nextLabel={t['student.holidays.nextPage']}
          />
        </>
      )}
    </section>
  )
}
