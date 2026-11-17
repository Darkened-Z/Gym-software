/**
 * Coaching section inside the member profile (members with an assigned trainer).
 * Workout, nutrition, progress and coach chat — driven by coach-ai.js and
 * api/fitness.php. All markup uses cz- classes (styled by coaching.css) so it
 * never collides with the profile page's own styles.
 */
window.MemberCoaching = (function () {
  'use strict';

  let currentMember = null;
  let currentActiveTab = 'home';
  let chatSelectedAgent = 'coach';
  let chatHistory = [];
  let root = null;
  let memberCodeForLoad = '';

  const TABS = [
    ['home', 'Today'],
    ['workout', 'Workout'],
    ['nutrition', 'Nutrition'],
    ['progress', 'Progress'],
    ['chat', 'Coach Chat']
  ];

  function modalRoot() {
    let el = document.getElementById('czModalRoot');
    if (!el) {
      el = document.createElement('div');
      el.id = 'czModalRoot';
      el.className = 'cz';
      document.body.appendChild(el);
    }
    return el;
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Local calendar date — toISOString() is UTC and gives "yesterday" before 5am in PKT.
  function localDate(d = new Date()) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  // Tape measurements are stored in cm; old entries typed in inches are < 60.
  function tapeCm(v) { v = Number(v); if (!v) return null; return v < 60 ? v * 2.54 : v; }
  function fmtTape(v) {
    const cm = tapeCm(v);
    return cm ? `${(cm / 2.54).toFixed(1)}″ <span style="color:var(--fg-4)">(${Math.round(cm)} cm)</span>` : '—';
  }

  function toast(msg, isError = false) {
    let t = document.getElementById('czToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'czToast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.className = 'cz cz-toast cz-show' + (isError ? ' cz-err' : '');
    clearTimeout(t._h);
    t._h = setTimeout(() => { t.className = 'cz cz-toast'; }, 2600);
  }

  // POST helper used by every form: disables the button, reports errors inline.
  async function postFit(action, payload, btn) {
    const code = currentMember.code || currentMember.member_code;
    const label = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    try {
      const res = await fetch(`api/fitness.php?action=${action}&code=${encodeURIComponent(code)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const d = await res.json();
      if (!d.success) throw new Error(d.message || 'Could not save');
      return true;
    } catch (err) {
      toast(err.message === 'Failed to fetch' ? 'No connection — try again' : err.message, true);
      return false;
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = label; }
    }
  }

  function closeModal() {
    const o = document.querySelector('.cz-modal-overlay');
    if (o) o.remove();
  }

  // Markdown bold helper
  function fmtMd(str) {
    return String(str || '').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  }

  function buildMemberAdapter(raw) {
    const fit = raw.fitness || {};
    const progress = raw.progress || fit.progress || [];
    const sessions = (raw.sessions || raw.recent_attendance || []).map(a => ({
      date: a.date,
      minutes: a.minutes || 55,
      rpe: a.rpe || 8,
      type: a.type || 'full-body'
    }));

    const parseList = (val) => {
      if (Array.isArray(val)) return val.filter(Boolean);
      if (typeof val === 'string' && val.trim()) return val.split(',').map(s => s.trim()).filter(Boolean);
      return [];
    };

    return {
      id: raw.id,
      code: raw.code || raw.member_code,
      name: raw.full_name || raw.name,
      firstName: raw.firstName || (raw.full_name || raw.name || '').split(' ')[0],
      phone: raw.phone || '',
      dob: raw.dob || '1996-01-01',
      gender: (raw.gender === 'female' || raw.gender === 'women' || raw.section === 'women') ? 'female' : 'male',
      height: Number(raw.height || fit.height || 170),
      goal: raw.goal || fit.goal || 'maintain',
      targetWeight: (raw.targetWeight ?? fit.target_weight) ? Number(raw.targetWeight ?? fit.target_weight) : null,
      goalNotes: raw.goalNotes || fit.goal_notes || '',
      experience: raw.experience || fit.experience || 'intermediate',
      vegetarian: !!(raw.vegetarian ?? fit.vegetarian),
      allergies: parseList(raw.allergies ?? fit.allergies),
      injuries: parseList(raw.injuries ?? fit.injuries),
      trainer: (typeof raw.trainer === 'string' ? raw.trainer : raw.trainer?.name) || 'Head Coach',
      trainerPhone: raw.trainerPhone || raw.trainer?.phone || '',
      monthlyFee: Number(raw.monthlyFee ?? raw.monthly_fee ?? 0),
      pkg: raw.pkg || raw.package_name || 'Standard Gym',
      dueAmount: Number(raw.due ?? raw.dueAmount ?? raw.due_amount ?? 0),
      nextDue: raw.nextDue || raw.due_date || 'N/A',
      joinDate: raw.joinDate || raw.join_date || '2026-01-01',
      progress: progress,
      sessions: sessions,
      sessionLogs: raw.sessionLogs || fit.session_logs || [],
      mealLogs: raw.mealLogs || fit.meal_logs || []
    };
  }

  // --- FIRST-TIME SETUP ---
  // Shown until the member has a height and one weigh-in; every number the
  // coach produces depends on these, so don't guess them.
  function renderSetupView(container) {
    const raw = currentMember;
    const fit = raw.fitness || {};
    const first = (raw.full_name || raw.name || '').split(' ')[0];
    container.innerHTML = `
      <div class="cz-page-head">
        <div>
          <h1>Let's set up your coach${first ? ', ' + escapeHtml(first) : ''}</h1>
          <p class="cz-sub">Takes a minute. Your workouts, calories and protein are calculated from this.</p>
        </div>
      </div>
      <div class="cz-card" style="max-width:640px;">
        <div class="cz-card-head"><h2>About you</h2><span class="cz-sub">One-time setup</span></div>
        <div class="cz-card-body">
          <form id="setupForm">
            <div class="cz-grid-2">
              <div class="cz-form-group">
                <label for="sWeight">Weight today (kg) *</label>
                <input type="number" step="0.1" min="25" max="300" id="sWeight" required inputmode="decimal" placeholder="e.g. 78">
              </div>
              <div class="cz-form-group">
                <label for="sHeightFt">Height (feet / inches) *</label>
                <div class="cz-pair">
                  <input type="number" min="3" max="7" id="sHeightFt" required inputmode="numeric" placeholder="ft" aria-label="Feet">
                  <input type="number" min="0" max="11" id="sHeightIn" inputmode="numeric" placeholder="in" aria-label="Inches">
                </div>
              </div>
            </div>
            <div class="cz-form-group">
              <label for="sWaist">Waist at belly button (optional)</label>
              <div class="cz-pair">
                <input type="number" step="0.5" id="sWaist" inputmode="decimal" placeholder="e.g. 34">
                <select id="sWaistUnit" aria-label="Waist unit"><option value="in" selected>inches</option><option value="cm">cm</option></select>
              </div>
              <div class="cz-hint">Used to estimate body fat, which sharpens your protein and calorie targets.</div>
            </div>
            <div class="cz-grid-2">
              <div class="cz-form-group">
                <label for="sGoal">Main goal *</label>
                <select id="sGoal">
                  <option value="lose" ${fit.goal === 'lose' ? 'selected' : ''}>Lose fat</option>
                  <option value="gain" ${fit.goal === 'gain' ? 'selected' : ''}>Build muscle</option>
                  <option value="maintain" ${!fit.goal || fit.goal === 'maintain' ? 'selected' : ''}>Get fit / maintain</option>
                </select>
              </div>
              <div class="cz-form-group">
                <label for="sTarget">Target weight (kg)</label>
                <input type="number" step="0.5" min="25" max="300" id="sTarget" inputmode="decimal" value="${fit.target_weight || ''}" placeholder="optional">
              </div>
            </div>
            <div class="cz-grid-2">
              <div class="cz-form-group">
                <label for="sExp">Gym experience</label>
                <select id="sExp">
                  <option value="novice">New (under 6 months)</option>
                  <option value="intermediate" selected>6 months – 2 years</option>
                  <option value="advanced">2+ years</option>
                </select>
              </div>
              <div class="cz-form-group">
                <label for="sVeg">Diet</label>
                <select id="sVeg">
                  <option value="0" ${fit.vegetarian ? '' : 'selected'}>Eat meat / eggs</option>
                  <option value="1" ${fit.vegetarian ? 'selected' : ''}>Vegetarian</option>
                </select>
              </div>
            </div>
            <div class="cz-form-group">
              <label for="sInj">Injuries or pain (optional)</label>
              <input type="text" id="sInj" value="${escapeHtml(fit.injuries || '')}" placeholder="e.g. knee, lower back">
            </div>
            <button type="submit" class="cz-btn-gold">Build my plan →</button>
          </form>
        </div>
      </div>
    `;

    if (fit.height) {
      const totalIn = fit.height / 2.54;
      document.getElementById('sHeightFt').value = Math.floor(totalIn / 12);
      document.getElementById('sHeightIn').value = Math.round(totalIn % 12);
    }

    document.getElementById('setupForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button[type=submit]');
      const ft = parseFloat(document.getElementById('sHeightFt').value) || 0;
      const inch = parseFloat(document.getElementById('sHeightIn').value) || 0;
      const heightCm = Math.round((ft * 12 + inch) * 2.54);
      if (heightCm < 120 || heightCm > 230) { toast('Check your height', true); return; }
      const waistRaw = parseFloat(document.getElementById('sWaist').value) || null;
      const waistCm = waistRaw ? (document.getElementById('sWaistUnit').value === 'in' ? waistRaw * 2.54 : waistRaw) : null;

      const okProfile = await postFit('save_profile', {
        height: heightCm,
        goal: document.getElementById('sGoal').value,
        target_weight: parseFloat(document.getElementById('sTarget').value) || null,
        experience: document.getElementById('sExp').value,
        vegetarian: document.getElementById('sVeg').value === '1',
        injuries: document.getElementById('sInj').value.trim()
      }, btn);
      if (!okProfile) return;
      const okWeight = await postFit('log_progress', {
        date: localDate(),
        weight: parseFloat(document.getElementById('sWeight').value),
        waist: waistCm ? Math.round(waistCm * 10) / 10 : null,
        notes: 'Starting weigh-in'
      }, btn);
      if (!okWeight) return;
      toast('Your plan is ready');
      currentActiveTab = 'home';
      loadMember(currentMember.code);
    });
  }

  // --- TAB: HOME OVERVIEW ---
  function renderHomeView(m, container) {
    const stats = window.COACH_AI.stats(m);
    const workout = window.COACH_AI.coachWorkout(m);
    const nutrition = window.COACH_AI.nutritionPlan(m);
    const narrative = window.COACH_AI.progressNarrative(m);

    const weightVal = stats.last ? `${Number(stats.last.weight).toFixed(1)} kg` : '—';
    const trendVal = stats.perWeek !== 0 ? `${stats.perWeek > 0 ? '+' : ''}${stats.perWeek.toFixed(2)} kg/wk` : 'Stable';
    const goalVal = stats.goalDone != null ? `${stats.goalDone}%` : '—';
    const freqVal = `${stats.sessionsPerWeek} / wk`;

    container.innerHTML = `
      <div class="cz-page-head">
        <div>
          <h1>Welcome back, ${m.firstName}</h1>
          <p class="cz-sub">Your personalized fitness program · ${(new Date()).toLocaleDateString('en-US', { weekday: 'long' })}</p>
        </div>
        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
          <div class="cz-ai-badge">Evidence-Based AI Coach</div>
          <button class="cz-btn-pill" onclick="MemberCoaching.openGoals()">⚙ Edit Goals</button>
        </div>
      </div>

      <!-- Stats Grid -->
      <div class="cz-stats">
        <div class="cz-stat">
          <span class="cz-k">Current Weight</span>
          <span class="cz-v cz-gold">${weightVal}</span>
          <span class="cz-delta">${stats.weightDelta !== 0 ? (stats.weightDelta > 0 ? '+' : '') + stats.weightDelta.toFixed(1) + ' kg overall' : 'At baseline'}</span>
        </div>
        <div class="cz-stat">
          <span class="cz-k">4-Wk Trend</span>
          <span class="cz-v ${stats.perWeek < 0 ? 'cz-green' : (stats.perWeek > 0 ? 'cz-gold' : 'cz-blue')}">${trendVal}</span>
          <span class="cz-delta">${stats.projDate ? 'Target by ' + stats.projDate : 'On track'}</span>
        </div>
        <div class="cz-stat">
          <span class="cz-k">Goal Progress</span>
          <span class="cz-v cz-green">${goalVal}</span>
          <span class="cz-delta">Target: ${m.targetWeight ? m.targetWeight + ' kg' : 'Maintain'}</span>
        </div>
        <div class="cz-stat">
          <span class="cz-k">Attendance</span>
          <span class="cz-v cz-blue">${freqVal}</span>
          <span class="cz-delta">Last 30-day rhythm</span>
        </div>
      </div>

      ${m.goalNotes ? `
        <div class="cz-info-note">
          <strong>📋 Coach Note from ${m.trainer}:</strong> ${fmtMd(m.goalNotes)}
        </div>
      ` : ''}

      <!-- Today's Workout Card -->
      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Today's Workout: ${workout.title}</h2>
          <span class="cz-sub">${workout.restDay ? 'REST & RECOVERY' : workout.estMinutes + ' MIN · ' + workout.focus.toUpperCase()}</span>
        </div>
        <div class="cz-card-body">
          <p>${fmtMd(workout.coachMessage)}</p>
          <ul class="cz-ex-list">
            ${workout.exercises.slice(0, 4).map((ex, i) => `
              <li>
                <span class="cz-n">${i + 1}</span>
                <span class="cz-name">${ex.name}</span>
                <span class="cz-rx">${ex.prescription}</span>
              </li>
            `).join('')}
          </ul>
          <div style="margin-top:1rem; display:flex; gap:.75rem;">
            <button class="cz-btn-gold" style="width:auto; padding:.6rem 1.25rem;" onclick="MemberCoaching.go('workout')">View Full Routine & Log →</button>
          </div>
        </div>
      </div>

      <!-- Nutrition Preview -->
      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Nutrition & Fuel Target</h2>
          <span class="cz-sub">${nutrition.calories} KCAL · ${nutrition.protein}G PROTEIN</span>
        </div>
        <div class="cz-card-body">
          <p>${fmtMd(nutrition.nutritionistMessage)}</p>
          <div class="cz-macro-grid">
            <div class="cz-macro"><div class="cz-k">Calories</div><div class="cz-v cz-gold">${nutrition.calories}<span class="cz-u">kcal</span></div></div>
            <div class="cz-macro"><div class="cz-k">Protein</div><div class="cz-v cz-green">${nutrition.protein}<span class="cz-u">g</span></div></div>
            <div class="cz-macro"><div class="cz-k">Carbs</div><div class="cz-v cz-blue">${nutrition.carbs}<span class="cz-u">g</span></div></div>
            <div class="cz-macro"><div class="cz-k">Healthy Fat</div><div class="cz-v">${nutrition.fat}<span class="cz-u">g</span></div></div>
          </div>
          <button class="cz-btn-pill" onclick="MemberCoaching.go('nutrition')">See 4-Meal Plan & Hydration →</button>
        </div>
      </div>

      <!-- Analyst Insight -->
      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Coach Analysis & Progress</h2>
          <span class="cz-sub">AI ANALYST</span>
        </div>
        <div class="cz-card-body">
          <p><strong>${fmtMd(narrative.headline)}</strong></p>
          ${narrative.paragraphs.map(p => `<p>${fmtMd(p)}</p>`).join('')}
          <div style="margin-top:1rem;">
            <button class="cz-btn-pill" onclick="MemberCoaching.openProgress()">+ Log New Weight</button>
          </div>
        </div>
      </div>
    `;
  }

  // --- TAB: WORKOUT ---
  function renderWorkoutView(m, container) {
    const workout = window.COACH_AI.coachWorkout(m);

    container.innerHTML = `
      <div class="cz-page-head">
        <div>
          <h1>Today's Workout Program</h1>
          <p class="cz-sub">${workout.title} · ${workout.focus}</p>
        </div>
        <button class="cz-btn-gold cz-auto" onclick="MemberCoaching.openWorkout()">+ Log Completed Session</button>
      </div>

      ${workout.coachMessage ? `<div class="cz-info-note">${fmtMd(workout.coachMessage)}</div>` : ''}
      ${workout.injuryNote ? `<div class="cz-warn-note">${fmtMd(workout.injuryNote)}</div>` : ''}

      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Warm-up Protocol</h2>
          <span class="cz-sub">5–8 MINUTES</span>
        </div>
        <div class="cz-card-body">
          <p>${workout.warmup}</p>
        </div>
      </div>

      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Main Prescriptions</h2>
          <span class="cz-sub">${workout.exercises.length} MOVEMENTS</span>
        </div>
        <div class="cz-card-body">
          <ul class="cz-ex-list">
            ${workout.exercises.map((ex, i) => `
              <li>
                <span class="cz-n">${i + 1}</span>
                <div>
                  <div class="cz-name">${ex.name}</div>
                  <div style="color:var(--fg-4); font-size:.78rem;">Target sets × reps with 2 min rest</div>
                </div>
                <span class="cz-rx">${ex.prescription}</span>
              </li>
            `).join('')}
          </ul>
        </div>
      </div>

      ${workout.finisher ? `
        <div class="cz-card">
          <div class="cz-card-head"><h2>Conditioning Finisher</h2><span class="cz-sub">OPTIONAL</span></div>
          <div class="cz-card-body"><p>${workout.finisher}</p></div>
        </div>
      ` : ''}

      ${m.sessionLogs && m.sessionLogs.length ? `
        <div class="cz-card">
          <div class="cz-card-head">
            <h2>Logged Workout History</h2>
            <span class="cz-sub">${m.sessionLogs.length} SESSIONS</span>
          </div>
          <div class="cz-card-body cz-scroll-x">
            <table class="cz-tbl">
              <thead>
                <tr style="border-bottom:1px solid var(--line); color:var(--fg-4);">
                  <th style="padding:.75rem 1rem;">DATE</th>
                  <th style="padding:.75rem 1rem;">SESSION</th>
                  <th style="padding:.75rem 1rem;">DURATION</th>
                  <th style="padding:.75rem 1rem;">RPE</th>
                  <th style="padding:.75rem 1rem;">NOTES</th>
                </tr>
              </thead>
              <tbody>
                ${m.sessionLogs.slice().reverse().map(s => `
                  <tr style="border-bottom:1px solid var(--line);">
                    <td style="padding:.75rem 1rem; font-variant-numeric:tabular-nums;">${s.date || '—'}</td>
                    <td style="padding:.75rem 1rem; font-weight:600; color:var(--fg);">${s.title || 'Workout'}</td>
                    <td style="padding:.75rem 1rem;">${s.minutes || 55} min</td>
                    <td style="padding:.75rem 1rem; color:var(--accent);">RPE ${s.rpe || 8}</td>
                    <td style="padding:.75rem 1rem; color:var(--fg-3);">${s.notes || ''}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        </div>
      ` : ''}
    `;
  }

  // --- TAB: NUTRITION ---
  function renderNutritionView(m, container) {
    const plan = window.COACH_AI.nutritionPlan(m);
    const todayStr = localDate();
    const todayMeals = (m.mealLogs || []).filter(x => x.date === todayStr);

    container.innerHTML = `
      <div class="cz-page-head">
        <div>
          <h1>Nutrition, Macros & Meals</h1>
          <p class="cz-sub">Calculated via ${plan.method} · Daily Target</p>
        </div>
        <button class="cz-btn-gold cz-auto" onclick="MemberCoaching.openMeal()">+ Log Meal</button>
      </div>

      <div class="cz-macro-grid">
        <div class="cz-macro"><div class="cz-k">Target Calories</div><div class="cz-v cz-gold">${plan.calories}<span class="cz-u">kcal</span></div></div>
        <div class="cz-macro"><div class="cz-k">Protein Target</div><div class="cz-v cz-green">${plan.protein}<span class="cz-u">g</span></div></div>
        <div class="cz-macro"><div class="cz-k">Carbohydrates</div><div class="cz-v cz-blue">${plan.carbs}<span class="cz-u">g</span></div></div>
        <div class="cz-macro"><div class="cz-k">Healthy Fats</div><div class="cz-v">${plan.fat}<span class="cz-u">g</span></div></div>
      </div>

      <div class="cz-info-note">
        ${fmtMd(plan.nutritionistMessage)}
      </div>

      ${m.mealLogs && m.mealLogs.length ? `
        <div class="cz-card">
          <div class="cz-card-head">
            <h2>Logged Meals</h2>
            <span class="cz-sub">${todayMeals.length} TODAY · ${m.mealLogs.length} TOTAL</span>
          </div>
          <div class="cz-card-body">
            ${m.mealLogs.slice(-6).reverse().map(ml => `
              <div style="display:flex; justify-content:space-between; align-items:center; padding:.6rem 0; border-bottom:1px solid var(--line);">
                <div>
                  <strong>${ml.name || 'Meal'}</strong> <span class="cz-mono" style="margin-left:.4rem;">${ml.date || ''}</span>
                  ${ml.desc ? `<div style="color:var(--fg-3); font-size:.82rem;">${ml.desc}</div>` : ''}
                </div>
                <div style="text-align:right; font-variant-numeric:tabular-nums; font-size:.82rem;">
                  <span style="color:var(--accent);">${ml.kcal || 0} kcal</span> · <span style="color:var(--green);">${ml.protein || 0}g P</span>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      ` : ''}

      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Evidence-Based 4-Meal Distribution</h2>
          <span class="cz-sub">~${plan.proteinPerMeal}G PROTEIN PER MEAL (ARAGON & SCHOENFELD)</span>
        </div>
        <div class="cz-card-body">
          ${plan.meals.map(ml => `
            <div class="cz-meal">
              <div class="cz-h">
                <b>${ml.name}</b>
                <small>~${ml.kcal} kcal</small>
              </div>
              <div class="cz-b">
                <ul>
                  ${ml.items.map(it => `<li>${it}</li>`).join('')}
                </ul>
              </div>
            </div>
          `).join('')}
        </div>
      </div>

      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Hydration & Supplements</h2>
          <span class="cz-sub">RECOVERY ESSENTIALS</span>
        </div>
        <div class="cz-card-body">
          <p>💧 <strong>Daily Water Target:</strong> Aim for <strong>${plan.hydration} Liters</strong> today (35 ml/kg baseline + 500 ml per training hour).</p>
          <div style="margin-top:1rem;">
            <strong>Recommended Evidence-Tiered Stack:</strong>
            <ul style="margin-top:.5rem; padding-left:1.2rem; color:var(--fg-2);">
              ${plan.supplements.map(s => `<li>${s}</li>`).join('')}
            </ul>
          </div>
        </div>
      </div>
    `;
  }

  // --- TAB: PROGRESS & TRENDS ---
  function renderProgressView(m, container) {
    const narrative = window.COACH_AI.progressNarrative(m);
    const stats = window.COACH_AI.stats(m);
    const badges = window.COACH_AI.achievements(m, m.sessionLogs, m.mealLogs);

    container.innerHTML = `
      <div class="cz-page-head">
        <div>
          <h1>Progress & Trend Analysis</h1>
          <p class="cz-sub">Rolling 4-week trendline and body metrics</p>
        </div>
        <button class="cz-btn-gold cz-auto" onclick="MemberCoaching.openProgress()">+ Log New Measurements</button>
      </div>

      <div class="cz-stats">
        <div class="cz-stat"><span class="cz-k">Starting</span><span class="cz-v">${stats.first ? stats.first.weight : '—'} kg</span><span class="cz-delta">${stats.first ? stats.first.date : ''}</span></div>
        <div class="cz-stat"><span class="cz-k">Latest</span><span class="cz-v cz-gold">${stats.last ? stats.last.weight : '—'} kg</span><span class="cz-delta">${stats.last ? stats.last.date : ''}</span></div>
        <div class="cz-stat"><span class="cz-k">Body Fat (US Navy)</span><span class="cz-v cz-blue">${stats.bf ? stats.bf.toFixed(1) + '%' : 'Log waist'}</span><span class="cz-delta">${stats.lbm ? stats.lbm.toFixed(1) + ' kg lean mass' : ''}</span></div>
        <div class="cz-stat"><span class="cz-k">Goal Status</span><span class="cz-v cz-green">${stats.goalDone != null ? stats.goalDone + '%' : '—'}</span><span class="cz-delta">Target: ${m.targetWeight || '—'} kg</span></div>
      </div>

      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Analyst Breakdown</h2>
          <span class="cz-sub">INSIGHTS</span>
        </div>
        <div class="cz-card-body">
          <p><strong>${fmtMd(narrative.headline)}</strong></p>
          ${narrative.paragraphs.map(p => `<p>${fmtMd(p)}</p>`).join('')}
        </div>
      </div>

      <!-- Milestones & Badges -->
      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Unlocked Achievements & Milestones</h2>
          <span class="cz-sub">${badges.unlocked.length} UNLOCKED · ${badges.streak.days} DAY STREAK</span>
        </div>
        <div class="cz-card-body">
          <div class="cz-milestones">
            ${badges.unlocked.map(b => `<span class="cz-milestone">${b.icon} ${b.title}</span>`).join('')}
            ${narrative.milestones.map(ms => `<span class="cz-milestone">🏆 ${ms}</span>`).join('')}
          </div>
        </div>
      </div>

      <!-- History Table -->
      <div class="cz-card">
        <div class="cz-card-head">
          <h2>Measurement Log History</h2>
          <span class="cz-sub">${m.progress.length} ENTRIES</span>
        </div>
        <div class="cz-card-body cz-scroll-x">
          <table class="cz-tbl">
            <thead>
              <tr style="border-bottom:1px solid var(--line); color:var(--fg-4);">
                <th style="padding:.75rem 1rem;">DATE</th>
                <th style="padding:.75rem 1rem;">WEIGHT</th>
                <th style="padding:.75rem 1rem;">WAIST</th>
                <th style="padding:.75rem 1rem;">CHEST</th>
                <th style="padding:.75rem 1rem;">NOTES</th>
              </tr>
            </thead>
            <tbody>
              ${m.progress.slice().reverse().map(p => `
                <tr style="border-bottom:1px solid var(--line);">
                  <td style="padding:.75rem 1rem; font-variant-numeric:tabular-nums;">${p.date}</td>
                  <td style="padding:.75rem 1rem; color:var(--accent); font-weight:600;">${p.weight} kg</td>
                  <td style="padding:.75rem 1rem;">${fmtTape(p.waist)}</td>
                  <td style="padding:.75rem 1rem;">${fmtTape(p.chest)}</td>
                  <td style="padding:.75rem 1rem; color:var(--fg-3);">${p.notes || ''}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  // --- TAB: CHAT ---
  function renderChatView(m, container) {
    const agents = window.COACH_AI.agents;
    const suggestions = window.COACH_AI.SUGGESTIONS[chatSelectedAgent] || [];

    if (!chatHistory.length) {
      chatHistory.push({
        sender: 'ai',
        agent: 'coach',
        text: `Hey ${m.firstName}! I'm your AI Coach. Ask me about your workout today, your macro targets, or your progress towards ${m.targetWeight ? m.targetWeight + ' kg' : 'your fitness goal'}.`
      });
    }

    container.innerHTML = `
      <div class="cz-page-head" style="margin-bottom:1rem;">
        <div>
          <h1>AI Coach Chat</h1>
          <p class="cz-sub">4 evidence-based agents trained on exercise & nutrition science</p>
        </div>
      </div>

      <div class="cz-chat">
        <!-- Agent Selector Tabs -->
        <div class="cz-chat-tabs" id="chatAgentTabs">
          ${Object.keys(agents).map(k => `
            <button class="${k === chatSelectedAgent ? 'cz-active' : ''}" data-agent="${k}">
              <span class="cz-em">${agents[k].emoji}</span> ${agents[k].name}
            </button>
          `).join('')}
        </div>

        <!-- Chat History -->
        <div class="cz-chat-scroll" id="chatScroll">
          ${chatHistory.map(msg => `
            <div class="cz-msg cz-${msg.sender}">
              ${msg.sender === 'ai' ? `<span class="cz-from">${agents[msg.agent]?.emoji || '🤖'} ${agents[msg.agent]?.name || 'Coach'}</span>` : ''}
              <div>${fmtMd(msg.text)}</div>
            </div>
          `).join('')}
        </div>

        <!-- Pre-populated Suggestions -->
        <div class="cz-suggests" id="chatSuggests">
          ${suggestions.map(s => `<button type="button" class="cz-sug-btn">${s}</button>`).join('')}
        </div>

        <!-- Input Bar -->
        <form class="cz-chat-input" id="chatForm">
          <input type="text" id="chatInput" placeholder="Ask your ${agents[chatSelectedAgent].name.toLowerCase()} anything..." autocomplete="off">
          <button type="submit">Send</button>
        </form>
      </div>
    `;

    // Chat tabs events
    document.querySelectorAll('#chatAgentTabs button').forEach(btn => {
      btn.addEventListener('click', () => {
        chatSelectedAgent = btn.dataset.agent;
        renderChatView(m, container);
      });
    });

    // Suggestions event
    document.querySelectorAll('.cz-sug-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        sendChatMessage(btn.textContent, m);
      });
    });

    // Form submit
    document.getElementById('chatForm').addEventListener('submit', (e) => {
      e.preventDefault();
      const inp = document.getElementById('chatInput');
      const text = inp.value.trim();
      if (text) {
        inp.value = '';
        sendChatMessage(text, m);
      }
    });

    // Scroll to bottom
    const scroll = document.getElementById('chatScroll');
    if (scroll) scroll.scrollTop = scroll.scrollHeight;
  }

  function sendChatMessage(text, m) {
    chatHistory.push({ sender: 'user', text });
    const reply = window.COACH_AI.chat(chatSelectedAgent, text, m);
    setTimeout(() => {
      chatHistory.push({ sender: 'ai', agent: reply.agent, text: reply.text });
      renderTab('chat');
    }, 150);
  }

  // 4. Modals (Edit Goals, Log Weight, Log Workout, Log Meal)
  function openModal(title, bodyHtml) {
    modalRoot().innerHTML = `
      <div class="cz-modal-overlay">
        <div class="cz-modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
          <div class="cz-modal-head">
            <h3>${escapeHtml(title)}</h3>
            <button class="cz-modal-close" type="button" aria-label="Close">&times;</button>
          </div>
          <div class="cz-modal-body">${bodyHtml}</div>
        </div>
      </div>`;
    const overlay = document.querySelector('.cz-modal-overlay');
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    overlay.querySelector('.cz-modal-close').addEventListener('click', closeModal);
    const first = overlay.querySelector('input, select, textarea');
    if (first) first.focus();
    return overlay.querySelector('form');
  }

  function bindSave(form, action, buildPayload, okMsg) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = buildPayload();
      if (!payload) return;
      if (await postFit(action, payload, form.querySelector('button[type=submit]'))) {
        closeModal();
        toast(okMsg);
        loadMember(currentMember.code);
      }
    });
  }

  const unitSelect = (id) => `<select id="${id}" aria-label="Unit"><option value="in" selected>inches</option><option value="cm">cm</option></select>`;
  const readTape = (valId, unitId) => {
    const v = parseFloat(document.getElementById(valId).value);
    if (!v) return null;
    return Math.round((document.getElementById(unitId).value === 'in' ? v * 2.54 : v) * 10) / 10;
  };

  const openGoals = function() {
    if (!currentMember) return;
    const m = buildMemberAdapter(currentMember);
    const totalIn = m.height / 2.54;
    const form = openModal('Goals & preferences', `
      <form id="goalsEditForm">
        <div class="cz-grid-2">
          <div class="cz-form-group">
            <label for="gGoal">Main goal</label>
            <select id="gGoal">
              <option value="lose" ${m.goal === 'lose' ? 'selected' : ''}>Lose fat</option>
              <option value="gain" ${m.goal === 'gain' ? 'selected' : ''}>Build muscle</option>
              <option value="maintain" ${m.goal === 'maintain' ? 'selected' : ''}>Get fit / maintain</option>
            </select>
          </div>
          <div class="cz-form-group">
            <label for="gTargetWeight">Target weight (kg)</label>
            <input type="number" step="0.5" min="25" max="300" id="gTargetWeight" inputmode="decimal" value="${m.targetWeight || ''}" placeholder="optional">
          </div>
        </div>
        <div class="cz-grid-2">
          <div class="cz-form-group">
            <label for="gHeightFt">Height (feet / inches)</label>
            <div class="cz-pair">
              <input type="number" min="3" max="7" id="gHeightFt" required value="${Math.floor(totalIn / 12)}" inputmode="numeric" aria-label="Feet">
              <input type="number" min="0" max="11" id="gHeightIn" value="${Math.round(totalIn % 12)}" inputmode="numeric" aria-label="Inches">
            </div>
          </div>
          <div class="cz-form-group">
            <label for="gExp">Gym experience</label>
            <select id="gExp">
              <option value="novice" ${m.experience === 'novice' ? 'selected' : ''}>New (under 6 months)</option>
              <option value="intermediate" ${m.experience === 'intermediate' ? 'selected' : ''}>6 months – 2 years</option>
              <option value="advanced" ${m.experience === 'advanced' ? 'selected' : ''}>2+ years</option>
            </select>
          </div>
        </div>
        <div class="cz-form-group">
          <label for="gVeg">Diet</label>
          <select id="gVeg">
            <option value="0" ${!m.vegetarian ? 'selected' : ''}>Eat meat / eggs</option>
            <option value="1" ${m.vegetarian ? 'selected' : ''}>Vegetarian</option>
          </select>
        </div>
        <div class="cz-form-group">
          <label for="gAllergies">Food allergies</label>
          <input type="text" id="gAllergies" value="${escapeHtml(m.allergies.join(', '))}" placeholder="e.g. dairy, eggs — leave blank if none">
        </div>
        <div class="cz-form-group">
          <label for="gInjuries">Injuries or pain</label>
          <input type="text" id="gInjuries" value="${escapeHtml(m.injuries.join(', '))}" placeholder="e.g. knee, shoulder, lower back">
        </div>
        <button type="submit" class="cz-btn-gold">Save</button>
      </form>`);
    bindSave(form, 'save_profile', () => {
      const cm = Math.round(((parseFloat(document.getElementById('gHeightFt').value) || 0) * 12 + (parseFloat(document.getElementById('gHeightIn').value) || 0)) * 2.54);
      if (cm < 120 || cm > 230) { toast('Check your height', true); return null; }
      return {
        goal: document.getElementById('gGoal').value,
        target_weight: parseFloat(document.getElementById('gTargetWeight').value) || null,
        height: cm,
        experience: document.getElementById('gExp').value,
        vegetarian: document.getElementById('gVeg').value === '1',
        allergies: document.getElementById('gAllergies').value.trim(),
        injuries: document.getElementById('gInjuries').value.trim()
      };
    }, 'Goals updated');
  };

  const openProgress = function() {
    const form = openModal('Log weigh-in', `
      <form id="progressLogForm">
        <div class="cz-form-group">
          <label for="mWeight">Weight (kg) *</label>
          <input type="number" step="0.1" min="25" max="300" id="mWeight" required inputmode="decimal" placeholder="e.g. 74.5">
        </div>
        <div class="cz-form-group">
          <label for="mWaist">Waist at belly button</label>
          <div class="cz-pair"><input type="number" step="0.5" id="mWaist" inputmode="decimal" placeholder="optional">${unitSelect('mWaistUnit')}</div>
        </div>
        <div class="cz-form-group">
          <label for="mChest">Chest</label>
          <div class="cz-pair"><input type="number" step="0.5" id="mChest" inputmode="decimal" placeholder="optional">${unitSelect('mChestUnit')}</div>
        </div>
        <div class="cz-form-group">
          <label for="mNotes">Notes</label>
          <input type="text" id="mNotes" maxlength="200" placeholder="e.g. Morning, before breakfast">
        </div>
        <div class="cz-hint" style="margin:-.25rem 0 1rem;">Weigh in once a week, in the morning before eating. A second entry on the same day replaces the first.</div>
        <button type="submit" class="cz-btn-gold">Save weigh-in</button>
      </form>`);
    bindSave(form, 'log_progress', () => ({
      date: localDate(),
      weight: parseFloat(document.getElementById('mWeight').value),
      waist: readTape('mWaist', 'mWaistUnit'),
      chest: readTape('mChest', 'mChestUnit'),
      notes: document.getElementById('mNotes').value.trim()
    }), 'Weigh-in saved');
  };

  const openWorkout = function() {
    const plan = window.COACH_AI.coachWorkout(buildMemberAdapter(currentMember));
    const form = openModal('Log workout', `
      <form id="workoutLogForm">
        <div class="cz-form-group">
          <label for="wTitle">Workout</label>
          <input type="text" id="wTitle" required maxlength="80" value="${escapeHtml(plan.title || 'Workout')}">
        </div>
        <div class="cz-grid-2">
          <div class="cz-form-group">
            <label for="wMinutes">Minutes</label>
            <input type="number" id="wMinutes" required min="5" max="300" inputmode="numeric" value="${plan.estMinutes || 55}">
          </div>
          <div class="cz-form-group">
            <label for="wRpe">How hard was it?</label>
            <select id="wRpe">
              <option value="6">Easy — could do much more</option>
              <option value="7">Moderate</option>
              <option value="8" selected>Hard — 2 reps left in the tank</option>
              <option value="9">Very hard</option>
              <option value="10">All-out</option>
            </select>
          </div>
        </div>
        <div class="cz-form-group">
          <label for="wNotes">Notes</label>
          <input type="text" id="wNotes" maxlength="200" placeholder="e.g. Bench 60 kg × 8">
        </div>
        <button type="submit" class="cz-btn-gold">Save workout</button>
      </form>`);
    bindSave(form, 'log_session', () => ({
      date: localDate(),
      title: document.getElementById('wTitle').value.trim(),
      minutes: parseInt(document.getElementById('wMinutes').value, 10) || 50,
      rpe: parseInt(document.getElementById('wRpe').value, 10) || 8,
      notes: document.getElementById('wNotes').value.trim()
    }), 'Workout logged');
  };

  const openMeal = function() {
    const h = new Date().getHours();
    const guess = h < 11 ? 'Breakfast' : h < 16 ? 'Lunch' : h < 19 ? 'Snack' : 'Dinner';
    const form = openModal('Log meal', `
      <form id="mealLogForm">
        <div class="cz-form-group">
          <label for="mType">Meal</label>
          <select id="mType">
            ${['Breakfast', 'Lunch', 'Snack', 'Dinner'].map(x => `<option ${x === guess ? 'selected' : ''}>${x}</option>`).join('')}
          </select>
        </div>
        <div class="cz-form-group">
          <label for="mDesc">What did you eat?</label>
          <input type="text" id="mDesc" maxlength="200" placeholder="e.g. 2 roti, chicken salan, raita">
        </div>
        <div class="cz-grid-2">
          <div class="cz-form-group">
            <label for="mKcal">Calories (approx.)</label>
            <input type="number" id="mKcal" min="0" max="5000" inputmode="numeric" placeholder="e.g. 650">
          </div>
          <div class="cz-form-group">
            <label for="mProtein">Protein (g, approx.)</label>
            <input type="number" id="mProtein" min="0" max="400" inputmode="numeric" placeholder="e.g. 35">
          </div>
        </div>
        <button type="submit" class="cz-btn-gold">Save meal</button>
      </form>`);
    bindSave(form, 'log_meal', () => ({
      date: localDate(),
      name: document.getElementById('mType').value,
      kcal: parseInt(document.getElementById('mKcal').value, 10) || 0,
      protein: parseInt(document.getElementById('mProtein').value, 10) || 0,
      desc: document.getElementById('mDesc').value.trim()
    }), 'Meal logged');
  };


  function renderShell() {
    const trainer = currentMember.trainer || 'your trainer';
    const phone = currentMember.trainerPhone || '';
    root.innerHTML = `
      <section class="cz cz-section" aria-labelledby="czTitle">
        <header class="cz-section-head">
          <div>
            <span class="cz-eyebrow">Coaching</span>
            <h2 id="czTitle" class="cz-title">Your training plan</h2>
            <p class="cz-coach-line">Coach: <b>${escapeHtml(trainer)}</b>${phone ? ` · <span class="cz-num">${escapeHtml(phone)}</span>` : ''}</p>
          </div>
        </header>
        <nav class="cz-tabs" role="tablist" aria-label="Coaching sections">
          ${TABS.map(([k, label]) => `<button type="button" role="tab" class="cz-tab" data-tab="${k}" aria-selected="false">${label}</button>`).join('')}
        </nav>
        <div class="cz-panel" id="czPanel" role="tabpanel"></div>
      </section>`;
    root.querySelectorAll('.cz-tab').forEach(btn => btn.addEventListener('click', () => go(btn.dataset.tab)));
  }

  function renderTab(tab) {
    currentActiveTab = tab;
    if (!currentMember || !root) return;
    const panel = document.getElementById('czPanel');
    if (!panel) return;
    root.querySelectorAll('.cz-tab').forEach(b => {
      const on = b.dataset.tab === tab;
      b.classList.toggle('cz-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    const tabs = root.querySelector('.cz-tabs');
    if (tabs) tabs.hidden = !!currentMember.needsSetup;

    if (currentMember.needsSetup) { renderSetupView(panel); return; }

    const m = buildMemberAdapter(currentMember);
    switch (tab) {
      case 'workout':   renderWorkoutView(m, panel); break;
      case 'nutrition': renderNutritionView(m, panel); break;
      case 'progress':  renderProgressView(m, panel); break;
      case 'chat':      renderChatView(m, panel); break;
      default:          renderHomeView(m, panel); break;
    }
  }

  function go(tab) {
    renderTab(tab);
    const head = root && root.querySelector('.cz-tabs');
    if (head && head.getBoundingClientRect().top < 0) head.scrollIntoView({ block: 'start' });
  }

  async function loadMember(code) {
    code = code || memberCodeForLoad;
    let res = null;
    for (let attempt = 0; attempt < 3 && !res; attempt++) {
      try {
        res = await fetch(`api/fitness.php?action=get&code=${encodeURIComponent(code)}`, { cache: 'no-store', credentials: 'same-origin' });
      } catch (err) {
        await new Promise(r => setTimeout(r, 600 * (attempt + 1)));
      }
    }
    if (!res) return;
    let data = null;
    try { data = await res.json(); } catch (err) { return; }
    if (!data || !data.success || !data.data) return;

    if (!data.data.hasTrainer) {
      root.innerHTML = '';
      currentMember = null;
      return;
    }
    const first = !currentMember;
    currentMember = data.data;
    currentMember.code = currentMember.code || currentMember.member_code || code;
    if (first) renderShell();
    renderTab(currentActiveTab);
  }

  function mount(container, memberCode) {
    root = container;
    memberCodeForLoad = memberCode;
    currentMember = null;
    currentActiveTab = 'home';
    chatHistory = [];
    if (!root || !window.COACH_AI) return;
    loadMember(memberCode);
  }

  return { mount, go, openGoals, openProgress, openWorkout, openMeal };
})();
