const config = require('./config');
const { createPool } = require('./db');
const { GymRepository } = require('./repository');
const { WhatsAppService } = require('./whatsapp-service');
const { startScheduler, runDailyCampaigns, runMonthlyCampaign } = require('./scheduler');

async function main() {
  const pool = createPool(config);
  const repository = new GymRepository(pool, config);
  const service = new WhatsAppService(config, repository);

  await service.start();

  const mode = String(process.env.WHATSAPP_MODE || 'daemon').toLowerCase();
  if (mode === 'daily') {
    console.log(await runDailyCampaigns({ config, repository, service }));
    await pool.end();
    return;
  }

  if (mode === 'monthly') {
    console.log(await runMonthlyCampaign({ config, repository, service }));
    await pool.end();
    return;
  }

  if (mode === 'queue') {
    console.log(await service.processQueue(50));
    await pool.end();
    return;
  }

  if (mode === 'inbound-only') {
    console.log('[whatsapp] inbound-only mode active');
    return;
  }

  const tasks = startScheduler({ config, repository, service });
  console.log(`[whatsapp] scheduler started with ${tasks.length} cron job(s)`);

  process.on('SIGINT', async () => {
    for (const task of tasks) task.stop();
    await service.stop();
    await pool.end();
    process.exit(0);
  });

  process.on('SIGTERM', async () => {
    for (const task of tasks) task.stop();
    await service.stop();
    await pool.end();
    process.exit(0);
  });
}

main().catch(async (error) => {
  console.error('[whatsapp] fatal error:', error);
  process.exitCode = 1;
});
