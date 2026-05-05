const { monthBounds, normalizePhone, phoneSearchVariants, dedupeKey } = require('./utils');

class GymRepository {
  constructor(pool, config) {
    this.pool = pool;
    this.config = config;
    this.memberTables = null;
    this.metadataCache = new Map();
    this.snapshotCache = { loadedAt: 0, rows: [] };
  }

  async listMemberTables() {
    if (this.memberTables) return this.memberTables;
    const [rows] = await this.pool.query(
      "SELECT TABLE_NAME AS table_name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'members\\_%' ORDER BY TABLE_NAME ASC"
    );
    this.memberTables = rows.map((row) => row.table_name).filter(Boolean);
    return this.memberTables;
  }

  async tableColumns(tableName) {
    if (this.metadataCache.has(tableName)) return this.metadataCache.get(tableName);
    const [rows] = await this.pool.query(
      'SELECT COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION ASC',
      [tableName]
    );
    const columns = rows.map((row) => row.column_name);
    this.metadataCache.set(tableName, columns);
    return columns;
  }

  async getDateColumn(tableName) {
    const columns = await this.tableColumns(tableName);
    return columns.includes('join_date') ? 'join_date' : (columns.includes('admission_date') ? 'admission_date' : null);
  }

  async getBirthdayColumn(tableName) {
    const columns = await this.tableColumns(tableName);
    const preferred = this.config.birthdayColumns.find((column) => columns.includes(column));
    return preferred || null;
  }

  attendanceTableFor(memberTable) {
    return memberTable.replace(/^members_/, 'attendance_');
  }

  genderFromTable(memberTable) {
    return memberTable.replace(/^members_/, '');
  }

  async loadMemberSnapshot() {
    const tables = await this.listMemberTables();
    const rows = [];

    for (const table of tables) {
      const dateColumn = await this.getDateColumn(table);
      const birthdayColumn = await this.getBirthdayColumn(table);
      const selectBirthday = birthdayColumn ? `m.${birthdayColumn} AS birthday` : 'NULL AS birthday';
      const selectJoin = dateColumn ? `m.${dateColumn} AS join_date` : 'NULL AS join_date';
      const attendanceTable = this.attendanceTableFor(table);
      const [memberRows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.email, m.status, m.next_fee_due_date, m.total_due_amount, m.monthly_fee, m.membership_type, ${selectJoin}, ${selectBirthday}, att.last_visit_at, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender
         FROM ${table} m
         LEFT JOIN (
           SELECT member_id, MAX(check_in) AS last_visit_at
           FROM ${attendanceTable}
           GROUP BY member_id
         ) att ON att.member_id = m.id
         WHERE m.phone IS NOT NULL AND m.phone <> ''`
      );
      rows.push(...memberRows);
    }

    this.snapshotCache = { loadedAt: Date.now(), rows };
    return rows;
  }

  async getMemberByPhone(phone) {
    const normalized = normalizePhone(phone, this.config.countryCode);
    const variants = phoneSearchVariants(phone, this.config.countryCode);
    const rows = await this.getSnapshot();

    const matches = rows.filter((row) => {
      const rowPhone = normalizePhone(row.phone, this.config.countryCode);
      if (!rowPhone) return false;
      return variants.some((variant) => rowPhone === variant || rowPhone.endsWith(variant.slice(-10)) || variant.endsWith(rowPhone.slice(-10)));
    });

    return matches.find((row) => normalizePhone(row.phone, this.config.countryCode) === normalized) || matches[0] || null;
  }

  async getSnapshot() {
    const ttl = 10 * 60 * 1000;
    if (!this.snapshotCache.rows.length || (Date.now() - this.snapshotCache.loadedAt) > ttl) {
      return this.loadMemberSnapshot();
    }
    return this.snapshotCache.rows;
  }

  async listExpiryCandidates(daysAhead) {
    return this.listByCondition('expiry_3_days', async (table, dateColumn) => {
      if (!dateColumn) return [];
      const [rows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.next_fee_due_date AS due_date, m.total_due_amount, m.status, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender
         FROM ${table} m
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
           AND m.next_fee_due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)`,
        [daysAhead]
      );
      return rows;
    });
  }

  async listExpiredToday() {
    return this.listByCondition('expiry_today', async (table) => {
      const [rows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.next_fee_due_date AS due_date, m.total_due_amount, m.status, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender
         FROM ${table} m
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
           AND m.next_fee_due_date = CURDATE()`
      );
      return rows;
    });
  }

  async listReengageCandidates(inactiveDays) {
    const tables = await this.listMemberTables();
    const rows = [];

    for (const table of tables) {
      const dateColumn = await this.getDateColumn(table);
      const attendanceTable = this.attendanceTableFor(table);
      if (!dateColumn) continue;

      const [memberRows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.status, m.${dateColumn} AS join_date, COALESCE(att.last_visit_at, m.${dateColumn}) AS last_visit_at, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender
         FROM ${table} m
         LEFT JOIN (
           SELECT member_id, MAX(check_in) AS last_visit_at
           FROM ${attendanceTable}
           GROUP BY member_id
         ) att ON att.member_id = m.id
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
           AND COALESCE(att.last_visit_at, m.${dateColumn}) < DATE_SUB(CURDATE(), INTERVAL ? DAY)`,
        [inactiveDays]
      );
      rows.push(...memberRows);
    }

    return rows;
  }

  async listBirthdaysToday() {
    const tables = await this.listMemberTables();
    const rows = [];
    for (const table of tables) {
      const birthdayColumn = await this.getBirthdayColumn(table);
      if (!birthdayColumn) continue;
      const [memberRows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.${birthdayColumn} AS birthday, m.status, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender
         FROM ${table} m
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
           AND m.${birthdayColumn} IS NOT NULL
           AND DAY(m.${birthdayColumn}) = DAY(CURDATE())
           AND MONTH(m.${birthdayColumn}) = MONTH(CURDATE())`
      );
      rows.push(...memberRows);
    }
    return rows;
  }

  async listMonthlyVisitSummaries(bounds = monthBounds()) {
    const tables = await this.listMemberTables();
    const rows = [];

    for (const table of tables) {
      const dateColumn = await this.getDateColumn(table);
      const attendanceTable = this.attendanceTableFor(table);
      if (!dateColumn) continue;

      const [memberRows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.status, m.${dateColumn} AS join_date, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender,
                COUNT(a.id) AS visits,
                MIN(DATE(a.check_in)) AS first_visit_date,
                MAX(DATE(a.check_in)) AS last_visit_date
         FROM ${table} m
         LEFT JOIN ${attendanceTable} a ON a.member_id = m.id
            AND DATE(a.check_in) BETWEEN ? AND ?
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
         GROUP BY m.id, m.member_code, m.name, m.phone, m.status, m.${dateColumn}`,
        [bounds.start, bounds.end]
      );
      rows.push(...memberRows);
    }

    return rows;
  }

  async listTodaySummary() {
    const tables = await this.listMemberTables();
    const rows = [];

    for (const table of tables) {
      const attendanceTable = this.attendanceTableFor(table);
      const [memberRows] = await this.pool.query(
        `SELECT m.id, m.member_code, m.name, m.phone, m.status, '${table}' AS member_table, '${this.genderFromTable(table)}' AS gender,
                COUNT(a.id) AS today_visits,
                MIN(DATE(a.check_in)) AS first_visit_date,
                MAX(DATE(a.check_in)) AS last_visit_date
         FROM ${table} m
         LEFT JOIN ${attendanceTable} a ON a.member_id = m.id
            AND DATE(a.check_in) = CURDATE()
         WHERE m.phone IS NOT NULL AND m.phone <> ''
           AND m.status = 'active'
         GROUP BY m.id, m.member_code, m.name, m.phone, m.status`);
      rows.push(...memberRows);
    }

    return rows;
  }

  async listByCondition(_tag, runner) {
    const tables = await this.listMemberTables();
    const rows = [];
    for (const table of tables) {
      const dateColumn = await this.getDateColumn(table);
      if (!dateColumn) continue;
      const memberRows = await runner(table, dateColumn);
      rows.push(...memberRows);
    }
    return rows;
  }

  async enqueueMessage(payload) {
    const [result] = await this.pool.query(
      `INSERT INTO whatsapp_outbox
        (campaign_key, dedupe_key, member_table, member_id, recipient_phone, message_type, payload_json, rendered_message, scheduled_for, status)
       VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
       ON DUPLICATE KEY UPDATE
        updated_at = CURRENT_TIMESTAMP`,
      [
        payload.campaignKey,
        payload.dedupeKey,
        payload.memberTable,
        payload.memberId,
        payload.recipientPhone,
        payload.messageType,
        JSON.stringify(payload.data ?? {}),
        payload.renderedMessage ?? null,
      ]
    );
    return result.insertId || null;
  }

  async claimPendingMessages(limit = 10) {
    const [rows] = await this.pool.query(
      `SELECT * FROM whatsapp_outbox
       WHERE status = 'pending' AND scheduled_for <= NOW() AND attempt_count < ?
       ORDER BY scheduled_for ASC, id ASC
       LIMIT ?`,
      [this.config.campaign.sendAttemptLimit, limit]
    );
    return rows;
  }

  async markProcessing(id) {
    const [result] = await this.pool.query(
      `UPDATE whatsapp_outbox SET status = 'processing', attempt_count = attempt_count + 1, last_attempt_at = NOW() WHERE id = ? AND status = 'pending'`,
      [id]
    );
    return result.affectedRows === 1;
  }

  async markSent(id, providerMessageId = null) {
    await this.pool.query(
      `UPDATE whatsapp_outbox SET status = 'sent', provider_message_id = ?, sent_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
      [providerMessageId, id]
    );
  }

  async markFailed(id, reason) {
    await this.pool.query(
      `UPDATE whatsapp_outbox SET status = 'failed', failure_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
      [reason, id]
    );
  }

  async logInbound(messageId, senderPhone, member, keyword, messageText, replyText) {
    const [result] = await this.pool.query(
      `INSERT IGNORE INTO whatsapp_inbound_messages
        (whatsapp_message_id, sender_phone, member_table, member_id, keyword, message_text, reply_text, handled_at)
       VALUES
        (?, ?, ?, ?, ?, ?, ?, NOW())`,
      [messageId, senderPhone, member?.member_table ?? null, member?.id ?? null, keyword ?? null, messageText, replyText ?? null]
    );
    if (result.affectedRows === 0) return false;
    return true;
  }

  buildRunKey(prefix, payload = '') {
    return dedupeKey([prefix, payload]);
  }

  formatSummaryForMember(row, bounds) {
    const visits = Number(row.visits ?? row.today_visits ?? 0);
    if (bounds) {
      return visits === 0
        ? `${row.name}, you had 0 visits in ${bounds.monthKey}. Keep showing up — consistency wins.`
        : `${row.name}, you visited ${visits} time(s) in ${bounds.monthKey}. Last visit: ${row.last_visit_date || 'N/A'}.`;
    }
    return `${row.name}, today you visited ${visits} time(s).`;
  }
}

module.exports = { GymRepository };
