const cron = require('node-cron');
const { monthBounds, monthDayKey, formatDate } = require('./utils');

function startScheduler({ config, repository, service }) {
  const tasks = [];

  tasks.push(cron.schedule(config.schedule.processCron, async () => {
    const result = await service.processQueue(10);
    console.log(`[whatsapp] queue processed: ${JSON.stringify(result)}`);
  }, { timezone: config.timezone }));

  tasks.push(cron.schedule(config.schedule.dailyCron, async () => {
    const result = await runDailyCampaigns({ config, repository, service });
    console.log(`[whatsapp] daily campaigns: ${JSON.stringify(result)}`);
  }, { timezone: config.timezone }));

  tasks.push(cron.schedule(config.schedule.monthlyCron, async () => {
    const result = await runMonthlyCampaign({ config, repository, service });
    console.log(`[whatsapp] monthly campaigns: ${JSON.stringify(result)}`);
  }, { timezone: config.timezone }));

  return tasks;
}

async function runDailyCampaigns({ config, repository, service }) {
  const runKey = `daily-${new Date().toISOString().slice(0, 10)}`;
  const summary = await ensureRun(repository, runKey, 'daily');
  const expiryCandidates = await repository.listExpiryCandidates(config.campaign.expiryReminderDays);
  const expiredToday = await repository.listExpiredToday();
  const reengageCandidates = await repository.listReengageCandidates(config.campaign.reengageDays);
  const birthdays = await repository.listBirthdaysToday();

  const queuedExpiry = await service.enqueueCampaign(
    'expiry_3_days',
    expiryCandidates,
    (member) => service.buildExpiryMessage(member, 'to avoid interruption'),
    'expiry-3d',
    (member) => member.due_date || member.next_fee_due_date || ''
  );
  const queuedExpired = await service.enqueueCampaign(
    'expiry_today',
    expiredToday,
    (member) => service.buildExpiryMessage(member, 'today'),
    'expiry-today',
    (member) => member.due_date || member.next_fee_due_date || ''
  );
  const queuedReengage = await service.enqueueCampaign(
    'reengage_14_days',
    reengageCandidates,
    (member) => service.buildReengageMessage(member),
    'reengage-14d',
    (member) => formatDate(new Date(member.last_visit_at || member.join_date || Date.now()))
  );
  const queuedBirthdays = await service.enqueueCampaign(
    'birthday_wish',
    birthdays,
    (member) => service.buildBirthdayMessage(member),
    'birthday',
    (member) => monthDayKey(member.birthday)
  );

  await finishRun(repository, summary, {
    expiryCandidates: expiryCandidates.length,
    expiredToday: expiredToday.length,
    reengageCandidates: reengageCandidates.length,
    birthdays: birthdays.length,
    queuedExpiry,
    queuedExpired,
    queuedReengage,
    queuedBirthdays
  });

  return {
    expiryCandidates: expiryCandidates.length,
    expiredToday: expiredToday.length,
    reengageCandidates: reengageCandidates.length,
    birthdays: birthdays.length,
    queuedExpiry,
    queuedExpired,
    queuedReengage,
    queuedBirthdays
  };
}

async function runMonthlyCampaign({ repository, service }) {
  const bounds = monthBounds();
  const runKey = `monthly-${bounds.monthKey}`;
  const summary = await ensureRun(repository, runKey, 'monthly');
  const summaries = await repository.listMonthlyVisitSummaries(bounds);
  const queued = await service.enqueueCampaign(
    'monthly_visit_summary',
    summaries,
    (member) => service.buildSummaryMessage(member, bounds),
    'monthly-summary',
    () => bounds.monthKey
  );

  await finishRun(repository, summary, {
    monthKey: bounds.monthKey,
    summaries: summaries.length,
    queued
  });

  return { monthKey: bounds.monthKey, summaries: summaries.length, queued };
}

async function ensureRun(repository, runKey, runType) {
  await repository.pool.query(
    `INSERT INTO whatsapp_campaign_runs (run_key, run_type, status) VALUES (?, ?, 'running')
     ON DUPLICATE KEY UPDATE status = 'running'`,
    [runKey, runType]
  );
  return runKey;
}

async function finishRun(repository, runKey, stats) {
  await repository.pool.query(
    `UPDATE whatsapp_campaign_runs SET status = 'completed', stats_json = ?, completed_at = NOW() WHERE run_key = ?`,
    [JSON.stringify(stats), runKey]
  );
}

module.exports = { startScheduler, runDailyCampaigns, runMonthlyCampaign };
