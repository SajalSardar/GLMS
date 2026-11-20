import { useEffect, useState } from 'react'
import { listHolidays, type Holiday } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import Pagination from '../components/Pagination'

const t = getCommonStrings('en')

const PAGE_SIZE = 10

const DAY_LABEL: Record<string, string> = {
  saturday: t['management.days.saturday'],
  sunday: t['management.days.sunday'],
  monday: t['management.days.monday'],
  tuesday: t['management.days.tuesday'],
  wednesday: t['management.days.wednesday'],
  thursday: t['management.days.thursday'],
  friday: t['management.days.friday'],
}

function dayOrDateLabel(holiday: Holiday): string {
  if (holiday.type === 'weekly') {
    return holiday.day_of_week ? (DAY_LABEL[holiday.day_of_week] ?? holiday.day_of_week) : '—'
  }

  if (!holiday.start_date || !holiday.end_date) return '—'
  return holiday.start_date === holiday.end_date ? holiday.start_date : `${holiday.start_date} – ${holiday.end_date}`
}

function typeLabel(holiday: Holiday): string {
  if (holiday.type === 'weekly') return t['management.fields.holidayTypeWeekly']
  if (holiday.type === 'government') return t['management.fields.holidayTypeGovernment']
  return t['management.fields.holidayTypeOther']
}

// Read-only — only a national_admin can create/update/delete the
// calendar (see the management portal's own Holiday Calendar page); a
// teacher only ever views it here.
export default function HolidaysPage() {
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

  const totalPages = Math.max(1, Math.ceil(holidays.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleHolidays = holidays.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.holidays.title']}</h1>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.holidays.loading']}</p>}

      {!loading && holidays.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.holidays.empty']}</p>}

      {!loading && holidays.length > 0 && (
        <>
          <div className="mt-6 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[32rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.holidays.colDay']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.holidays.colName']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.holidays.colType']}</th>
                </tr>
              </thead>
              <tbody>
                {visibleHolidays.map((holiday, index) => (
                  <tr key={holiday.id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {(currentPage - 1) * PAGE_SIZE + index + 1}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{dayOrDateLabel(holiday)}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">
                      {holiday.bn_name ?? holiday.name}
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
            previousLabel={t['teacher.holidays.previousPage']}
            nextLabel={t['teacher.holidays.nextPage']}
          />
        </>
      )}
    </section>
  )
}
