/** Formats cents as a euro price string, e.g. formatCents(299) -> "2,99 €". */
export function formatCents(cents) {
  return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format((cents ?? 0) / 100);
}
