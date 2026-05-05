const crypto = require('crypto');

function digitsOnly(value) {
  return String(value ?? '').replace(/\D+/g, '');
}

function normalizePhone(value, countryCode = '92') {
  let digits = digitsOnly(value);
  if (!digits) return '';
  if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.startsWith(countryCode)) return digits;
  if (digits.length === 11 && digits.startsWith('0')) {
    return countryCode + digits.slice(1);
  }
  if (digits.length === 10 && countryCode) {
    return countryCode + digits;
  }
  return digits;
}

function phoneSearchVariants(value, countryCode = '92') {
  const normalized = normalizePhone(value, countryCode);
  if (!normalized) return [];
  const last10 = normalized.slice(-10);
  return Array.from(new Set([normalized, last10, digitsOnly(value)]).values()).filter(Boolean);
}

function renderTemplate(template, variables = {}) {
  let output = String(template ?? '');
  for (const [key, value] of Object.entries(variables)) {
    const pattern = new RegExp(`{{\\s*${escapeRegExp(key)}\\s*}}`, 'g');
    output = output.replace(pattern, String(value ?? ''));
  }
  return output;
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function dedupeKey(parts) {
  return crypto.createHash('sha1').update(parts.map((part) => String(part ?? '')).join('|')).digest('hex');
}

function monthBounds(date = new Date()) {
  const current = new Date(date.getTime());
  const firstDayThisMonth = new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth(), 1));
  const firstDayPrevMonth = new Date(Date.UTC(firstDayThisMonth.getUTCFullYear(), firstDayThisMonth.getUTCMonth() - 1, 1));
  const lastDayPrevMonth = new Date(Date.UTC(firstDayThisMonth.getUTCFullYear(), firstDayThisMonth.getUTCMonth(), 0));
  return {
    monthKey: `${firstDayPrevMonth.getUTCFullYear()}-${String(firstDayPrevMonth.getUTCMonth() + 1).padStart(2, '0')}`,
    start: formatDate(firstDayPrevMonth),
    end: formatDate(lastDayPrevMonth)
  };
}

function formatDate(date) {
  return date.toISOString().slice(0, 10);
}

function formatDateTime(date) {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

function monthDayKey(date) {
  const parsed = date instanceof Date ? date : new Date(date);
  if (Number.isNaN(parsed.getTime())) return '';
  return `${String(parsed.getUTCMonth() + 1).padStart(2, '0')}-${String(parsed.getUTCDate()).padStart(2, '0')}`;
}

function titleCase(value) {
  return String(value ?? '')
    .toLowerCase()
    .replace(/\b\w/g, (m) => m.toUpperCase());
}

module.exports = {
  digitsOnly,
  normalizePhone,
  phoneSearchVariants,
  renderTemplate,
  dedupeKey,
  monthBounds,
  formatDate,
  formatDateTime,
  monthDayKey,
  titleCase
};
