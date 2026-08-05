'use client';

import { useMemo, useState } from 'react';
import { ArrowDownAZ, ArrowUpAZ, FileSpreadsheet, Printer } from 'lucide-react';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

type SortDirection = 'asc' | 'desc';

export interface ReportColumn<T> {
  key: string;
  label: string;
  sortable?: boolean;
  align?: 'left' | 'right' | 'center';
  render: (row: T) => React.ReactNode;
  sortValue?: (row: T) => string | number | null | undefined;
  exportValue?: (row: T) => string | number | null | undefined;
}

export function ReportTable<T>({
  title,
  subtitle,
  rows,
  columns,
  emptyLabel,
  exportName,
  initialSortKey,
  initialSortDirection = 'asc',
}: {
  title: string;
  subtitle?: string;
  rows: T[];
  columns: ReportColumn<T>[];
  emptyLabel: string;
  exportName: string;
  initialSortKey?: string;
  initialSortDirection?: SortDirection;
}) {
  const [sortKey, setSortKey] = useState<string | null>(initialSortKey ?? null);
  const [sortDirection, setSortDirection] = useState<SortDirection>(initialSortDirection);

  const sortedRows = useMemo(() => {
    if (!sortKey) return rows;
    const column = columns.find((entry) => entry.key === sortKey);
    if (!column) return rows;

    const accessor = column.sortValue ?? column.exportValue;
    if (!accessor) return rows;

    return [...rows].sort((left, right) => {
      const leftValue = accessor(left);
      const rightValue = accessor(right);
      const comparison = compareValues(leftValue, rightValue);
      return sortDirection === 'asc' ? comparison : comparison * -1;
    });
  }, [columns, rows, sortDirection, sortKey]);

  function toggleSort(column: ReportColumn<T>) {
    if (!column.sortable) return;
    if (sortKey === column.key) {
      setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setSortKey(column.key);
    setSortDirection('asc');
  }

  function exportCsv() {
    const fileName = buildExportFileName(exportName);
    const header = columns.map((column) => escapeCsv(column.label)).join(',');
    const body = sortedRows
      .map((row) =>
        columns
          .map((column) => escapeCsv(String((column.exportValue ?? column.sortValue)?.(row) ?? '')))
          .join(',')
      )
      .join('\r\n');

    const blob = new Blob([`\uFEFF${header}\r\n${body}`], {
      type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${fileName}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function exportPdf() {
    const fileName = buildExportFileName(exportName);
    const document = new jsPDF({
      orientation: columns.length > 6 ? 'landscape' : 'portrait',
      unit: 'pt',
      format: 'a4',
    });

    document.setProperties({
      title: fileName,
      subject: title,
    });

    document.setFontSize(16);
    document.text(title, 40, 40);

    if (subtitle) {
      document.setFontSize(10);
      document.setTextColor(71, 85, 105);
      const subtitleLines = document.splitTextToSize(subtitle, document.internal.pageSize.getWidth() - 80);
      document.text(subtitleLines, 40, 58);
      document.setTextColor(15, 23, 42);
    }

    autoTable(document, {
      startY: subtitle ? 80 : 58,
      head: [columns.map((column) => String(column.label))],
      body: sortedRows.map((row) =>
        columns.map((column) => String((column.exportValue ?? column.sortValue)?.(row) ?? ''))
      ),
      styles: {
        fontSize: 8,
        cellPadding: 5,
        overflow: 'linebreak',
        valign: 'top',
      },
      headStyles: {
        fillColor: [248, 250, 252],
        textColor: [71, 85, 105],
        lineColor: [203, 213, 225],
        lineWidth: 0.5,
        fontStyle: 'bold',
      },
      bodyStyles: {
        lineColor: [226, 232, 240],
        lineWidth: 0.5,
        textColor: [15, 23, 42],
      },
      margin: { top: 40, right: 40, bottom: 40, left: 40 },
      didDrawPage: () => {
        const pageSize = document.internal.pageSize;
        const pageHeight = pageSize.getHeight();
        const pageWidth = pageSize.getWidth();
        const pageText = `Generated ${formatExportTimestamp(new Date())}`;

        document.setFontSize(9);
        document.setTextColor(100, 116, 139);
        document.text(pageText, 40, pageHeight - 18);
        document.text(
          `Page ${document.getCurrentPageInfo().pageNumber}`,
          pageWidth - 70,
          pageHeight - 18
        );
      },
    });

    document.save(`${fileName}.pdf`);
  }

  return (
    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h3 className="text-base font-bold text-slate-900">{title}</h3>
          {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={exportCsv}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
          >
            <FileSpreadsheet className="h-4 w-4" />
            Export Excel
          </button>
          <button
            type="button"
            onClick={exportPdf}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700"
          >
            <Printer className="h-4 w-4" />
            Export PDF
          </button>
        </div>
      </div>

      <div className="content-scrollbar overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  className={`px-5 py-3 ${alignmentClass(column.align)} ${column.sortable ? 'cursor-pointer select-none' : ''}`}
                  onClick={() => toggleSort(column)}
                >
                  <span className={`inline-flex items-center gap-1 ${column.align === 'right' ? 'justify-end' : ''}`}>
                    {column.label}
                    {column.sortable && sortKey === column.key ? (
                      sortDirection === 'asc' ? (
                        <ArrowDownAZ className="h-3.5 w-3.5" />
                      ) : (
                        <ArrowUpAZ className="h-3.5 w-3.5" />
                      )
                    ) : null}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {sortedRows.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-5 py-14 text-center text-slate-400">
                  {emptyLabel}
                </td>
              </tr>
            ) : (
              sortedRows.map((row, rowIndex) => (
                <tr key={rowIndex} className="align-top hover:bg-slate-50/70">
                  {columns.map((column) => (
                    <td key={column.key} className={`px-5 py-4 ${alignmentClass(column.align)}`}>
                      {column.render(row)}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function compareValues(left: string | number | null | undefined, right: string | number | null | undefined) {
  if (left == null && right == null) return 0;
  if (left == null) return 1;
  if (right == null) return -1;

  if (typeof left === 'number' && typeof right === 'number') {
    return left - right;
  }

  return String(left).localeCompare(String(right), undefined, {
    numeric: true,
    sensitivity: 'base',
  });
}

function alignmentClass(align: ReportColumn<unknown>['align']) {
  return align === 'right'
    ? 'text-right'
    : align === 'center'
      ? 'text-center'
      : 'text-left';
}

function escapeCsv(value: string) {
  const normalized = value.replaceAll('\r', ' ').replaceAll('\n', ' | ');
  if (normalized.includes('"') || normalized.includes(',') || normalized.includes('|')) {
    return `"${normalized.replaceAll('"', '""')}"`;
  }
  return normalized;
}

function buildExportFileName(base: string) {
  return `${base}-${formatExportTimestamp(new Date())}`;
}

function formatExportTimestamp(date: Date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
    '-',
    String(date.getHours()).padStart(2, '0'),
    String(date.getMinutes()).padStart(2, '0'),
    String(date.getSeconds()).padStart(2, '0'),
  ].join('');
}
