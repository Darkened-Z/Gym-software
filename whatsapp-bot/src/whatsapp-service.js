const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { normalizePhone, renderTemplate, formatDate, monthBounds, titleCase, formatDateTime } = require('./utils');

class WhatsAppService {
  constructor(config, repository) {
    this.config = config;
    this.repository = repository;
    this.client = null;
    this.ready = false;
  }

  async start() {
    if (this.config.dryRun) {
      this.ready = true;
      return this;
    }

    this.client = new Client({
      authStrategy: new LocalAuth({ dataPath: this.config.sessionDir }),
      puppeteer: {
        headless: this.config.headless,
        executablePath: this.config.puppeteerExecutablePath || undefined,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
      }
    });

    this.client.on('qr', (qr) => {
      if (this.config.showQr) {
        qrcode.generate(qr, { small: true });
      }
      console.log('[whatsapp] QR received, scan it from the WhatsApp app.');
    });

    this.client.on('ready', () => {
      this.ready = true;
      console.log('[whatsapp] client ready');
    });

    this.client.on('message', async (message) => {
      try {
        await this.handleInbound(message);
      } catch (error) {
        console.error('[whatsapp] inbound handling failed:', error.message);
      }
    });

    await this.client.initialize();
    return this;
  }

  async stop() {
    if (this.client) {
      await this.client.destroy();
    }
  }

  async sendPhoneMessage(phone, text) {
    const normalized = normalizePhone(phone, this.config.countryCode);
    if (!normalized) {
      throw new Error('Missing recipient phone number');
    }

    const rendered = String(text ?? '').trim();
    if (!rendered) {
      throw new Error('Missing message body');
    }

    if (this.config.dryRun) {
      console.log(`[dry-run] ${normalized}: ${rendered}`);
      return { id: `dry-run-${Date.now()}` };
    }

    const chatId = `${normalized}@c.us`;
    const result = await this.client.sendMessage(chatId, rendered);
    return { id: result?.id?._serialized || result?.id?.id || null };
  }

  async processOutboxRow(row) {
    if (!(await this.repository.markProcessing(row.id))) {
      return { skipped: true };
    }

    try {
      const payload = typeof row.payload_json === 'string'
        ? JSON.parse(row.payload_json)
        : (row.payload_json || {});
      const message = row.rendered_message || payload.message || '';
      const sent = await this.sendPhoneMessage(row.recipient_phone, message);
      await this.repository.markSent(row.id, sent.id || null);
      return { sent: true };
    } catch (error) {
      await this.repository.markFailed(row.id, error.message);
      throw error;
    }
  }

  async processQueue(limit = 10) {
    const rows = await this.repository.claimPendingMessages(limit);
    let sent = 0;
    let failed = 0;

    for (const row of rows) {
      try {
        const result = await this.processOutboxRow(row);
        if (result.sent) sent += 1;
      } catch (error) {
        failed += 1;
      }
      await sleep(this.config.campaign.throttleMs);
    }

    return { sent, failed, scanned: rows.length };
  }

  async handleInbound(message) {
    const rawText = String(message.body ?? '').trim();
    if (!rawText || message.fromMe || message.from?.endsWith('@g.us')) return null;

    const keyword = rawText.split(/\s+/)[0].toUpperCase();
    const knownKeywords = ['RENEW', 'SCHEDULE', 'STATUS'];
    if (!knownKeywords.includes(keyword)) {
      return null;
    }

    const senderPhone = normalizePhone(String(message.from || '').replace(/@c\.us$/, ''), this.config.countryCode);
    const messageId = message.id?._serialized || message.id?.id || `${senderPhone}-${Date.now()}`;
    const claimed = await this.repository.logInbound(messageId, senderPhone, null, keyword, rawText, null);
    if (!claimed) {
      return { handled: false, reason: 'duplicate_message' };
    }

    const member = await this.repository.getMemberByPhone(senderPhone);
    if (!member) {
      const replyText = 'We could not match this number to a CRM member record. Please contact the gym office.';
      await this.repository.pool.query(
        `UPDATE whatsapp_inbound_messages SET reply_text = ?, member_table = NULL, member_id = NULL, handled_at = NOW() WHERE whatsapp_message_id = ?`,
        [replyText, messageId]
      );
      await message.reply(replyText);
      return { handled: false, reason: 'member_not_found' };
    }

    let replyText = '';
    if (keyword === 'RENEW') {
      const dueDate = member.next_fee_due_date ? formatDate(new Date(member.next_fee_due_date)) : 'soon';
      replyText = `Assalam o Alaikum ${member.name}. Your current status is ${titleCase(member.status || 'active')}. Due amount: ${member.total_due_amount ?? 0}. Next due date: ${dueDate}. ${this.config.replyRenew}`;
    } else if (keyword === 'SCHEDULE') {
      replyText = `Assalam o Alaikum ${member.name}. Gym schedule: ${this.config.replySchedule}.`;
    } else if (keyword === 'STATUS') {
      const lastVisit = member.last_visit_at ? formatDateTime(new Date(member.last_visit_at)) : 'No visit recorded';
      replyText = `Assalam o Alaikum ${member.name}. Member code: ${member.member_code}. Status: ${titleCase(member.status || 'active')}. Due amount: ${member.total_due_amount ?? 0}. Last visit: ${lastVisit}. ${this.config.replyStatusFooter}`;
    }

    await this.repository.pool.query(
      `UPDATE whatsapp_inbound_messages SET member_table = ?, member_id = ?, reply_text = ?, handled_at = NOW() WHERE whatsapp_message_id = ?`,
      [member.member_table, member.id, replyText, messageId]
    );
    await message.reply(replyText);
    return { handled: true, keyword };
  }

  async sendToMember(member, messageBody) {
    const sent = await this.sendPhoneMessage(member.phone, messageBody);
    return sent;
  }

  async enqueueCampaign(campaignKey, members, buildMessage, runKeyPrefix, scopeBuilder = () => '') {
    let queued = 0;
    for (const member of members) {
      const messageBody = buildMessage(member);
      if (!messageBody) continue;
      const scope = String(scopeBuilder(member) || '').trim();
      const dedupeKey = this.repository.buildRunKey(runKeyPrefix, `${member.member_table}|${member.id}|${campaignKey}|${scope}`);
      const payload = {
        campaignKey,
        dedupeKey,
        memberTable: member.member_table,
        memberId: member.id,
        recipientPhone: member.phone,
        messageType: campaignKey,
        data: member,
        renderedMessage: messageBody,
        scheduledFor: new Date().toISOString().slice(0, 19).replace('T', ' ')
      };
      const insertId = await this.repository.enqueueMessage(payload);
      if (insertId) queued += 1;
    }
    return queued;
  }

  buildExpiryMessage(member, prefix) {
    const dueDate = member.next_fee_due_date ? formatDate(new Date(member.next_fee_due_date)) : 'soon';
    return `Assalam o Alaikum ${member.name}. Your membership expires on ${dueDate}. Please renew ${prefix}.`;
  }

  buildReengageMessage(member) {
    const lastVisit = member.last_visit_at ? formatDate(new Date(member.last_visit_at)) : (member.join_date ? formatDate(new Date(member.join_date)) : 'recently');
    return `Assalam o Alaikum ${member.name}. We missed you — your last visit was on ${lastVisit}. Please come back to the gym.`;
  }

  buildBirthdayMessage(member) {
    return `Happy Birthday ${member.name}! Wishing you strength, health, and a great year ahead from the gym team.`;
  }

  buildSummaryMessage(member, bounds) {
    const visits = Number(member.visits ?? 0);
    const lastVisit = member.last_visit_date || 'N/A';
    return `Assalam o Alaikum ${member.name}. Your visit summary for ${bounds.monthKey}: ${visits} visit(s). Last visit: ${lastVisit}.`;
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = { WhatsAppService };
