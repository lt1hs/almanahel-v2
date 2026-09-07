function pad(value: number): string {
  return String(value).padStart(2, "0");
}

/** Value for `<input type="datetime-local">` from the viewer's local clock. */
export function localDateTimeInputValue(date = new Date()): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Convert a datetime-local wall-clock string to a UTC ISO instant.
 * Already-zoned strings are preserved as the same instant.
 */
export function localDateTimeToIso(value: string, fallback = new Date()): string {
  const raw = String(value ?? "").trim();
  if (!raw) return fallback.toISOString();

  if (/[zZ]|[+-]\d{2}:\d{2}$/.test(raw)) {
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return fallback.toISOString();
    return parsed.toISOString();
  }

  const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/);
  if (!match) {
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return fallback.toISOString();
    return parsed.toISOString();
  }

  const [, year, month, day, hour, minute, second] = match;
  return new Date(
    Number(year),
    Number(month) - 1,
    Number(day),
    Number(hour),
    Number(minute),
    Number(second ?? 0)
  ).toISOString();
}
