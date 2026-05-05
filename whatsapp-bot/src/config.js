require('dotenv').config({ path: require('path').resolve(__dirname, '..', '.env') });

function boolEnv(name, fallback = false) {
  const value = process.env[name];
  if (value === undefined) return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function intEnv(name, fallback) {
  const value = parseInt(process.env[name], 10);
  return Number.isFinite(value) ? value : fallback;
}

function stringEnv(name, fallback = '') {
  const value = process.env[name];
  return value === undefined || value === '' ? fallback : value;
}

function buildConfig() {
  const birthdayColumns = stringEnv('WHATSAPP_BIRTHDAY_COLUMNS', 'date_of_birth,dob,birthday,birth_date')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

  return {
    timezone: stringEnv('WHATSAPP_TIMEZONE', 'Asia/Karachi'),
    dryRun: boolEnv('WHATSAPP_DRY_RUN', false),
    headless: boolEnv('WHATSAPP_HEADLESS', true),
    showQr: boolEnv('WHATSAPP_QR', true),
    sessionDir: stringEnv('WHATSAPP_SESSION_DIR', './session'),
    countryCode: stringEnv('WHATSAPP_COUNTRY_CODE', '92'),
    defaultGymName: stringEnv('WHATSAPP_DEFAULT_GYM_NAME', 'Your Gym'),
    replySchedule: stringEnv('WHATSAPP_REPLY_SCHEDULE', 'Mon-Sat 6:00 AM-10:00 PM'),
    replyRenew: stringEnv('WHATSAPP_REPLY_RENEW', 'Reply with your member code or visit the front desk to renew membership.'),
    replyStatusFooter: stringEnv('WHATSAPP_REPLY_STATUS_FOOTER', 'If this number is wrong, reply STOP and the team will update your record.'),
    adminAlertPhone: stringEnv('WHATSAPP_ADMIN_ALERT_PHONE', ''),
    database: {
      host: stringEnv('DB_HOST', 'localhost'),
      port: intEnv('DB_PORT', 3306),
      name: stringEnv('DB_NAME', 'gym_management'),
      user: stringEnv('DB_USERNAME', 'root'),
      password: stringEnv('DB_PASSWORD', '')
    },
    schedule: {
      dailyCron: stringEnv('WHATSAPP_DAILY_CRON', '0 8 * * *'),
      processCron: stringEnv('WHATSAPP_PROCESS_CRON', '*/5 * * * *'),
      monthlyCron: stringEnv('WHATSAPP_MONTHLY_CRON', '15 8 1 * *')
    },
    campaign: {
      expiryReminderDays: intEnv('WHATSAPP_EXPIRY_REMINDER_DAYS', 3),
      reengageDays: intEnv('WHATSAPP_REENGAGE_DAYS', 14),
      inboundRetryWindowMinutes: intEnv('WHATSAPP_INBOUND_RETRY_WINDOW_MINUTES', 5),
      sendAttemptLimit: intEnv('WHATSAPP_SEND_ATTEMPT_LIMIT', 3),
      throttleMs: intEnv('WHATSAPP_MESSAGE_THROTTLE_MS', 1200)
    },
    birthdayColumns,
    puppeteerExecutablePath: stringEnv('WHATSAPP_PUPPETEER_EXECUTABLE_PATH', ''),
    scheduleText: stringEnv('WHATSAPP_REPLY_SCHEDULE', 'Mon-Sat 6:00 AM-10:00 PM')
  };
}

module.exports = buildConfig();
