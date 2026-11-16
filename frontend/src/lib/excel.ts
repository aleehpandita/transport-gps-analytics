import { fetchReportSheets, type ReportFilters, type ReportKind, type ReportPageOptions } from './apiClient'

// Descarga de reportes en Excel (.xlsx). El servidor manda las hojas en JSON
// (format=xlsx) y aquí se arma el archivo con write-excel-file, que se carga solo
// cuando alguien descarga (no pesa en el panel).
//
// Los números llegan como números, así que en Excel se pueden sumar y filtrar.

const MAX_COLUMN_WIDTH = 60

export async function downloadReportExcel(kind: ReportKind, filters: ReportFilters, options: ReportPageOptions = {}): Promise<void> {
  const [{ default: writeXlsxFile }, report] = await Promise.all([
    import('write-excel-file/browser'),
    fetchReportSheets(kind, filters, options),
  ])

  const sheets = report.sheets.map((sheet) => {
    const header = sheet.headers.map((h) => ({ value: h, fontWeight: 'bold' as const }))
    const rows = sheet.rows.map((row) =>
      row.map((value) => {
        if (value === null || value === '') return null
        return typeof value === 'number' ? { value, type: Number } : { value: String(value), type: String }
      }),
    )

    // Ancho de cada columna según lo más largo que tenga
    const columns = sheet.headers.map((h, i) => ({
      width: Math.min(
        MAX_COLUMN_WIDTH,
        Math.max(h.length, ...sheet.rows.map((r) => String(r[i] ?? '').length)) + 2,
      ),
    }))

    return {
      // Excel no acepta nombres de hoja de más de 31 caracteres
      sheet: sheet.name.slice(0, 31),
      data: [header, ...rows],
      columns,
      stickyRowsCount: 1,
    }
  })

  await writeXlsxFile(sheets).toFile(report.filename)
}
