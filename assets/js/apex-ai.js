/* ============================================================================
   APEX FIT — AI engine (rule-based, evidence-informed)

   Design philosophy: every response is derived from published fitness/
   nutrition science applied to *this* member's data. No canned text; the
   coach message references their actual delta, the nutritionist honors the
   Helms protein floor and Israetel fat minimum, the analyst uses a rolling
   4-week trend for projections. Swappable with a real /api/ai endpoint —
   see README swap-in notes.

   References cited in comments below:
   - Mifflin–St Jeor (1990) — BMR, gold standard for lean/moderate BMI
   - Katch–McArdle — BMR using lean body mass (used when we can estimate LBM)
   - Compendium of Physical Activities (Ainsworth) — MET-based TDEE
   - Helms et al. (2014) — protein 2.3–3.1 g/kg LBM for aggressive cut
   - Aragon & Schoenfeld (2018) — meal timing / protein distribution
   - Renaissance Periodization — MEV/MAV/MRV volume landmarks
   - Israetel et al. — 0.5–1.5 g/kg/day fat floor for hormonal health
   - Katch–McArdle Navy body-fat equation (US Navy circumference method)
   - Prochaska–DiClemente TTM (stages of change) — used in chat fallback tone
   ============================================================================ */

window.APEX_AI = (function () {
  const getTodayStr = () => (window.APEX_DATA && window.APEX_DATA.TODAY) || new Date().toISOString().slice(0, 10);
  const rand = (arr) => arr[Math.floor(Math.random() * arr.length)];
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const round = (n, d = 0) => { const p = 10 ** d; return Math.round(n * p) / p; };
  const daysBetween = (a, b) => Math.max(1, Math.round((new Date(b) - new Date(a)) / 86400000));

  // Linear regression for trend fitting (weight over days) — used for
  // projection instead of naive first-to-last slope.
  function linreg(xs, ys) {
    const n = xs.length;
    if (n < 2) return { slope: 0, intercept: ys[0] || 0 };
    const sx = xs.reduce((a, b) => a + b, 0);
    const sy = ys.reduce((a, b) => a + b, 0);
    const sxx = xs.reduce((a, b) => a + b * b, 0);
    const sxy = xs.reduce((a, b, i) => a + b * ys[i], 0);
    const slope = (n * sxy - sx * sy) / (n * sxx - sx * sx);
    const intercept = (sy - slope * sx) / n;
    return { slope, intercept };
  }

  // Rolling window average — smooths noise. Returns array parallel to input.
  function rolling(vals, window) {
    return vals.map((_, i) => {
      const from = Math.max(0, i - window + 1);
      const slice = vals.slice(from, i + 1);
      return slice.reduce((a, b) => a + b, 0) / slice.length;
    });
  }

  // ------ Body composition helpers ----------------------------------------

  // US Navy circumference method for body fat %. Requires waist (we have),
  // neck (we DON'T reliably have — use anthropometric default from height),
  // and — for women — hip (we approximate as thighs × 1.15 when absent).
  // Reference: Hodgdon & Beckett (1984) for the Navy equations.
  function navyBodyFat(m, entry) {
    if (!m.height || !entry || !entry.waist) return null;
    // Approximate neck circumference from height (cm) — Bloch/Beltrán-Sánchez
    // give ~ 0.216 × height for males, 0.207 × height for females.
    const neck = m.gender === 'female' ? m.height * 0.207 : m.height * 0.216;
    if (m.gender === 'female') {
      // Women: 495 / (1.29579 − 0.35004 × log10(waist + hip − neck) + 0.22100 × log10(height)) − 450
      const hip = entry.thighs ? entry.thighs * 1.55 : entry.waist * 1.15;
      const val = 495 / (1.29579 - 0.35004 * Math.log10(entry.waist + hip - neck) + 0.22100 * Math.log10(m.height)) - 450;
      return clamp(val, 8, 55);
    }
    // Men: 495 / (1.0324 − 0.19077 × log10(waist − neck) + 0.15456 × log10(height)) − 450
    if (entry.waist <= neck) return null;
    const val = 495 / (1.0324 - 0.19077 * Math.log10(entry.waist - neck) + 0.15456 * Math.log10(m.height)) - 450;
    return clamp(val, 4, 45);
  }

  function leanMass(m, entry) {
    const bf = navyBodyFat(m, entry);
    if (bf == null || !entry) return null;
    return entry.weight * (1 - bf / 100);
  }

  // ------ Aggregate stats -------------------------------------------------

  function stats(m) {
    const p = (m.progress || []).slice().sort((a, b) => new Date(a.date) - new Date(b.date));
    const first = p[0] || null, last = p[p.length - 1] || null;

    // Rolling 4-week (or all-data) trendline via linreg on day index
    let trendSlope = 0, projTarget = null, projDate = null, rolling4 = [];
    if (p.length >= 2) {
      const t0 = new Date(p[0].date).getTime();
      const days = p.map((r) => (new Date(r.date).getTime() - t0) / 86400000);
      const weights = p.map((r) => r.weight);
      rolling4 = rolling(weights, 4);
      const { slope, intercept } = linreg(days, rolling4);
      trendSlope = slope; // kg / day
      if (m.targetWeight != null && Math.abs(slope) > 1e-5) {
        const daysToTarget = (m.targetWeight - intercept) / slope - days[days.length - 1];
        if (daysToTarget > 0 && daysToTarget < 730) {
          projTarget = daysToTarget;
          const d = new Date(getTodayStr());
          d.setDate(d.getDate() + Math.round(daysToTarget));
          projDate = d.toISOString().slice(0, 10);
        }
      }
    }

    const weightDelta = last && first ? last.weight - first.weight : 0;
    const waistDelta = last && first ? last.waist - first.waist : 0;
    const perWeek = trendSlope * 7; // kg / week from trend
    const days = last && first ? daysBetween(first.date, last.date) : 1;

    // Bodyfat / LBM (US Navy)
    const bf = navyBodyFat(m, last);
    const lbm = leanMass(m, last);

    // BMI + category
    const bmi = last ? last.weight / ((m.height / 100) ** 2) : null;

    // Target progress %
    const goalDone = m.targetWeight != null && first && last
      ? clamp(Math.round(((last.weight - first.weight) / (m.targetWeight - first.weight)) * 100), 0, 100)
      : null;

    // Sessions in the last 14 days (better than "sessions.length / 7")
    const nowMs = new Date(getTodayStr()).getTime();
    const sessions14 = (m.sessions || []).filter((s) => (nowMs - new Date(s.date).getTime()) / 86400000 <= 14);
    const sessionsPerWeek = round(sessions14.length / 2, 1);

    // Adherence signal: last 7 vs prior 7 days
    const sessions7 = sessions14.filter((s) => (nowMs - new Date(s.date).getTime()) / 86400000 <= 7).length;
    const priorSessions7 = sessions14.length - sessions7;
    const adherenceDrop = priorSessions7 - sessions7; // positive = slowing down

    // Plateau: <0.15 kg change over 14 days
    const cutoff14 = new Date(nowMs - 14 * 86400000).toISOString().slice(0, 10);
    const last14 = p.filter((r) => r.date >= cutoff14);
    const plateau = last14.length >= 2 && Math.abs(last14[last14.length - 1].weight - last14[0].weight) < 0.15;

    // Pace safety flags
    const pctPerWeek = last ? Math.abs(perWeek) / last.weight * 100 : 0;
    const paceTooFastLoss = m.goal === 'lose' && pctPerWeek > 1.0;
    const paceTooFastGain = m.goal === 'gain' && pctPerWeek > 0.5;

    const age = m.dob ? Math.floor((nowMs - new Date(m.dob).getTime()) / (365.25 * 86400000)) : 30;

    return {
      first, last, weightDelta, waistDelta, days, bmi, goalDone,
      trendSlope, perWeek, projTarget, projDate,
      bf, lbm, sessionsPerWeek, sessions7, adherenceDrop, plateau,
      paceTooFastLoss, paceTooFastGain, age
    };
  }

  // ============================ NUTRITION ==================================
  // Total daily energy expenditure — Mifflin–St Jeor, then Katch–McArdle
  // when LBM is available (more accurate for muscular/lean individuals).
  function bmrMifflin(gender, w, h, age) {
    return gender === 'female' ? (10 * w + 6.25 * h - 5 * age - 161) : (10 * w + 6.25 * h - 5 * age + 5);
  }
  function bmrKatch(lbm) { return 370 + 21.6 * lbm; }

  // Activity multiplier from sessions/week + assumed non-training NEAT.
  // Sedentary 1.2 · light 1.375 · moderate 1.55 · very 1.725 · extra 1.9
  function activityMult(sessionsPerWeek) {
    if (sessionsPerWeek >= 6) return 1.725;
    if (sessionsPerWeek >= 4) return 1.55;
    if (sessionsPerWeek >= 2) return 1.375;
    return 1.2;
  }

  function calories(m) {
    const s = stats(m);
    const w = s.last ? s.last.weight : 70;
    // Prefer Katch–McArdle if we have lean mass; fall back to Mifflin.
    const bmr = s.lbm ? bmrKatch(s.lbm) : bmrMifflin(m.gender || 'male', w, m.height || 170, s.age);
    const mult = activityMult(s.sessionsPerWeek);
    const tdee = Math.round(bmr * mult);

    // Goal-adjusted target with safety guardrails.
    // Loss: 0.5-1% of bodyweight/week (~500-1000 kcal deficit is aggressive).
    // Gain: 0.25-0.5% of bodyweight/week (~200-400 kcal surplus).
    let target = tdee;
    if (m.goal === 'lose') {
      // ~15-20% deficit, capped so we don't push someone into starvation.
      const deficit = clamp(tdee * 0.18, 300, 750);
      target = Math.round(tdee - deficit);
      // Never below floor: 22 × bodyweight (kcal) — hormonal safety.
      target = Math.max(target, Math.round(22 * w));
    } else if (m.goal === 'gain') {
      const surplus = clamp(tdee * 0.10, 150, 350);
      target = Math.round(tdee + surplus);
    }
    return { bmr: Math.round(bmr), tdee, target, method: s.lbm ? 'Katch–McArdle (LBM ' + Math.round(s.lbm) + ' kg)' : 'Mifflin–St Jeor' };
  }

  function macros(m) {
    const c = calories(m);
    const s = stats(m);
    const w = s.last ? s.last.weight : 70;
    const lbm = s.lbm || w * 0.85;

    // Protein — Helms 2014 recommendations (per kg LBM in a cut, per kg BW
    // otherwise). Higher floor during aggressive deficit to spare muscle.
    let proteinG;
    if (m.goal === 'lose') proteinG = Math.round(lbm * 2.6);      // 2.3–3.1 g/kg LBM
    else if (m.goal === 'gain') proteinG = Math.round(w * 1.8);    // 1.6–2.0 g/kg BW
    else proteinG = Math.round(w * 1.6);                            // maintenance

    // Fat floor — Israetel: 0.5 g/kg minimum, prefer 20–35% of kcal.
    // Aim ~25% of kcal, floor at 0.5 g/kg.
    let fatG = Math.round((c.target * 0.25) / 9);
    fatG = Math.max(fatG, Math.round(w * 0.5));

    // Carbs — remainder.
    const carbsG = Math.max(0, Math.round((c.target - proteinG * 4 - fatG * 9) / 4));

    // Per-meal protein target for MPS: 0.4 g/kg per meal × 4 meals.
    const proteinPerMeal = Math.round((w * 0.4));

    // Fiber — 14 g per 1000 kcal (US Dietary Guidelines).
    const fiberG = Math.round(c.target / 1000 * 14);

    return {
      calories: c.target, protein: proteinG, carbs: carbsG, fat: fatG,
      fiber: fiberG, proteinPerMeal,
      tdee: c.tdee, bmr: c.bmr, method: c.method
    };
  }

  function nutritionPlan(m) {
    const mc = macros(m);
    const s = stats(m);
    const w = s.last ? s.last.weight : 70;
    const veg = m.vegetarian;

    // Sources — split by veg / omnivore
    const P = veg
      ? { name: 'paneer / dal / tofu / whey / greek yogurt', per100: { g: 20 } }
      : { name: 'chicken / eggs / whey / greek yogurt / fish', per100: { g: 25 } };

    // Sample day scaled to macros. Distribute protein across 4 meals (Aragon
    // & Schoenfeld: even distribution optimises MPS).
    const meals = [
      { name: 'Breakfast', kcal: Math.round(mc.calories * 0.28), items: [
          veg ? `Paneer bhurji (150 g) + 2 whole-wheat toast — ~${mc.proteinPerMeal} g protein` : `3 whole eggs + 100 g Greek yogurt + oats + banana — ~${mc.proteinPerMeal} g protein`,
          '1 scoop whey shake with milk',
          `Fruit or 30 g nuts for fibre + healthy fat`
        ] },
      { name: 'Lunch', kcal: Math.round(mc.calories * 0.32), items: [
          veg ? `Rajma / dal + 1 cup basmati rice + sabzi + salad — ~${mc.proteinPerMeal} g protein` : `180 g chicken breast + 1 cup rice + mixed veg + salad — ~${mc.proteinPerMeal} g protein`
        ] },
      { name: m.goal === 'gain' ? 'Pre-workout' : 'Snack', kcal: Math.round(mc.calories * 0.12), items: [
          'Greek yogurt + honey + oats',
          'Black coffee 30 min before training'
        ] },
      { name: 'Dinner', kcal: Math.round(mc.calories * 0.28), items: [
          veg ? `Tofu stir-fry with vegetables + 2 rotis + curd — ~${mc.proteinPerMeal} g protein` : `150 g grilled fish or lean beef + 2 rotis + steamed vegetables + curd — ~${mc.proteinPerMeal} g protein`
        ] }
    ];

    // Hydration: 35 ml/kg baseline + 500 ml per hour of training.
    const trainMin = (m.sessions || []).slice(-4).reduce((a, b) => a + (b.minutes || 0), 0) / Math.max(1, Math.min(4, (m.sessions || []).length));
    const trainingWater = trainMin / 60 * 0.5;
    const hydration = round(w * 35 / 1000 + trainingWater, 1);

    // Supplement stack — evidence-tiered (Examine.com A-tier).
    const stack = {
      always: ['Whey protein (fill the gap on low-protein days)', 'Creatine monohydrate 5 g/day', 'Vitamin D3 2000 IU (regional deficiency common)'],
      cut: ['Caffeine 3 mg/kg 30 min pre-workout', 'Omega-3 (EPA/DHA) 2 g/day'],
      gain: ['Creatine monohydrate 5 g/day', 'Post-workout carbs + protein window'],
      maintain: ['Multivitamin', 'Omega-3 1 g/day']
    };
    const supplements = [...stack.always, ...(stack[m.goal] || stack.maintain)]
      .filter((_, i, arr) => arr.indexOf(arr[i]) === i);

    // Allergy + safety pass
    const allergiesNote = (m.allergies || []).length ? `⚠ Watch: ${m.allergies.join(', ')}. I'd swap any listed foods for a matching-macro alternative.` : null;

    // Pace + guardrail messaging
    let msg;
    if (m.goal === 'gain') {
      const surplus = mc.calories - mc.tdee;
      msg = `Target **${mc.calories} kcal** today (${surplus} above your ${mc.tdee} kcal maintenance) with **${mc.protein} g protein** floor — that's ${round(mc.protein / w, 1)} g/kg. Spread it across 4 meals of ~${mc.proteinPerMeal} g each for max MPS.`;
      if (s.paceTooFastGain) msg += ` **Heads up:** you're gaining faster than 0.5%/wk — that's above the lean-bulk sweet spot. Ease off ~150 kcal to keep the ratio muscle-to-fat clean.`;
    } else if (m.goal === 'lose') {
      const deficit = mc.tdee - mc.calories;
      msg = `Target **${mc.calories} kcal** today (${deficit} deficit vs your ${mc.tdee} kcal maintenance). Protein floor: **${mc.protein} g** — ${round(mc.protein / (s.lbm || w * 0.85), 1)} g per kg of lean mass, per Helms. That's non-negotiable in a cut — you're either eating protein or losing muscle.`;
      if (s.paceTooFastLoss) msg += ` **Slow down.** You're losing >1%/wk — that's territory where you start burning muscle. Add 150 kcal back this week.`;
      if (s.plateau) msg += ` **Plateau detected** — weight hasn't moved in 2 weeks. Drop 100 kcal (protein stays), or add a walking day. Don't slash more than that.`;
    } else {
      msg = `Maintenance day — **${mc.calories} kcal** with **${mc.protein} g protein**. Prioritise food quality over macros this month.`;
    }
    if (m.injuries && m.injuries.length) msg += ` Working around: ${m.injuries.join(', ')} — extra protein helps recovery.`;

    return {
      calories: mc.calories, protein: mc.protein, carbs: mc.carbs, fat: mc.fat,
      fiber: mc.fiber, proteinPerMeal: mc.proteinPerMeal,
      tdee: mc.tdee, bmr: mc.bmr, method: mc.method,
      meals, hydration, allergiesNote,
      nutritionistMessage: msg,
      supplements,
      refeedNote: m.goal === 'lose' ? 'Once a week, bring calories back to maintenance with an extra 100–150 g carbs — helps leptin + training performance.' : null
    };
  }

  // ============================ COACH — evidence-based programming =========
  //
  // Volume landmarks per muscle group (Renaissance Periodization):
  //   MEV = min effective volume, MAV = max adaptive, MRV = max recoverable.
  // Weekly sets targets for hypertrophy (novice–intermediate).
  const VOLUME = {
    chest: { mev: 8,  mav: 15, mrv: 22 },
    back:  { mev: 10, mav: 18, mrv: 25 },
    legs:  { mev: 8,  mav: 16, mrv: 20 },  // quads OR hamstrings
    shoulders: { mev: 8, mav: 16, mrv: 22 },
    arms:  { mev: 6,  mav: 14, mrv: 20 },
    core:  { mev: 0,  mav: 12, mrv: 25 }
  };

  // Movement pattern library. Prescription is [sets × reps @ RPE].
  const MOVES = {
    // Compound push
    bench:     { name: 'Barbell Bench Press', pattern: 'push', comp: true },
    incline:   { name: 'Incline DB Bench Press', pattern: 'push', comp: true },
    ohp:       { name: 'Overhead Press', pattern: 'push', comp: true },
    dbShoulder:{ name: 'DB Shoulder Press', pattern: 'push', comp: true },
    dip:       { name: 'Weighted Dip', pattern: 'push', comp: true },
    dbFly:     { name: 'DB Fly', pattern: 'push', comp: false },
    lateral:   { name: 'Lateral Raise', pattern: 'push', comp: false },
    triExt:    { name: 'Overhead Tricep Ext.', pattern: 'push', comp: false },
    // Pull
    pullup:    { name: 'Weighted Pull-up', pattern: 'pull', comp: true },
    row:       { name: 'Barbell Row', pattern: 'pull', comp: true },
    cableRow:  { name: 'Chest-supported Row', pattern: 'pull', comp: true },
    facePull:  { name: 'Face Pull', pattern: 'pull', comp: false },
    curl:      { name: 'Barbell Curl', pattern: 'pull', comp: false },
    // Legs — quad
    squat:     { name: 'Back Squat', pattern: 'quad', comp: true },
    fSquat:    { name: 'Front Squat', pattern: 'quad', comp: true },
    bulgarian: { name: 'Bulgarian Split Squat', pattern: 'quad', comp: false },
    legPress:  { name: 'Leg Press', pattern: 'quad', comp: false },
    legExt:    { name: 'Leg Extension', pattern: 'quad', comp: false },
    // Legs — hip / posterior
    deadlift:  { name: 'Conventional Deadlift', pattern: 'hip', comp: true },
    rdl:       { name: 'Romanian Deadlift', pattern: 'hip', comp: true },
    hipThrust: { name: 'Barbell Hip Thrust', pattern: 'hip', comp: true },
    legCurl:   { name: 'Lying Leg Curl', pattern: 'hip', comp: false },
    calf:      { name: 'Standing Calf Raise', pattern: 'hip', comp: false },
    // Cardio / conditioning
    zone2:     { name: 'Treadmill Zone 2 Walk (incline 8%)', pattern: 'cardio', comp: true },
    hiit:      { name: 'Assault Bike HIIT (10×30/30)', pattern: 'cardio', comp: true },
    stairs:    { name: 'Stairmaster', pattern: 'cardio', comp: false },
    // Core
    plank:     { name: 'Plank', pattern: 'core', comp: false },
    hanging:   { name: 'Hanging Knee Raise', pattern: 'core', comp: false },
    pallof:    { name: 'Pallof Press', pattern: 'core', comp: false }
  };

  // Injury-aware swap table
  const INJURY_SWAPS = {
    knee: { squat: 'legPress', bulgarian: 'legExt', deadlift: 'rdl', hiit: 'zone2' },
    'low back': { deadlift: 'rdl', row: 'cableRow', squat: 'fSquat' },
    shoulder: { bench: 'incline', ohp: 'dbShoulder', pullup: 'cableRow' },
    'post-partum core recovery': { plank: 'pallof', hanging: 'pallof', squat: 'bulgarian', deadlift: 'rdl' }
  };
  function applySwaps(moves, injuries) {
    if (!injuries || !injuries.length) return moves;
    return moves.map((m) => {
      for (const inj of injuries) {
        for (const key of Object.keys(INJURY_SWAPS)) {
          if (inj.toLowerCase().includes(key)) {
            const swaps = INJURY_SWAPS[key];
            // Find move key by name reverse-lookup
            const k = Object.keys(MOVES).find((kk) => MOVES[kk].name === m.name);
            if (k && swaps[k]) return { ...MOVES[swaps[k]] };
          }
        }
      }
      return m;
    });
  }

  // RPE-based prescription: strength uses lower reps + higher RPE, hypertrophy
  // moderate rep + moderate RPE. Autoregulation-friendly.
  function prescribe(kind, compound) {
    // Kind: 'strength' | 'hyper' | 'endurance' | 'cardio'
    if (kind === 'cardio') return '30–45 min @ Zone 2 (HR ~120–140)';
    if (kind === 'strength') return compound ? '5×3 @ RPE 8' : '4×6 @ RPE 8';
    if (kind === 'endurance') return '3×15 @ RPE 6–7';
    return compound ? '4×6–8 @ RPE 7–8' : '3×10–12 @ RPE 8';
  }

  // Build a session out of the movement library with volume balance.
  function buildSession(splitName, focus, moveKeys, kind, m, injuries) {
    let moves = moveKeys.map((k) => ({ ...MOVES[k] }));
    moves = applySwaps(moves, injuries);
    // Prescribe: first compound is always heaviest, accessories moderate.
    const rx = moves.map((mv, i) => ({
      name: mv.name,
      prescription: mv.pattern === 'cardio' ? '30–45 min @ Zone 2' : i === 0 ? prescribe(kind, true) : prescribe(kind, mv.comp)
    }));
    return rx;
  }

  // Rotate splits by day-of-week for deterministic per-day content that
  // respects 48-hour muscle recovery.
  function coachWorkout(m) {
    const g = m.goal || 'maintain';
    const s = stats(m);
    const day = new Date(getTodayStr()).getDay(); // 0=Sun..6=Sat

    // Build weekly template based on goal
    let weekly;
    if (g === 'gain') {
      // Upper–Lower–Push–Pull–Legs–Rest–Optional (6 sessions/wk for MRV-approaching)
      weekly = [
        { key: 'rest',  title: 'Rest', focus: 'recovery + walk', moves: [], kind: 'rest' },
        { key: 'push',  title: 'Push · Chest / Shoulders / Triceps', focus: 'compound push volume', moves: ['bench','ohp','incline','lateral','dbFly','triExt'], kind: 'hyper' },
        { key: 'pull',  title: 'Pull · Back / Biceps',               focus: 'vertical + horizontal pull', moves: ['pullup','row','cableRow','facePull','curl'], kind: 'hyper' },
        { key: 'quad',  title: 'Legs · Quad-dominant',               focus: 'squat pattern',           moves: ['squat','bulgarian','legPress','legExt','calf'], kind: 'hyper' },
        { key: 'upper', title: 'Upper · Hypertrophy',                focus: 'higher-rep upper',        moves: ['incline','pullup','dbShoulder','cableRow','lateral','curl'], kind: 'hyper' },
        { key: 'hip',   title: 'Legs · Hip-dominant',                focus: 'deadlift + posterior',    moves: ['deadlift','rdl','hipThrust','legCurl','calf'], kind: 'strength' },
        { key: 'rest',  title: 'Rest', focus: 'recovery + walk', moves: [], kind: 'rest' }
      ];
    } else if (g === 'lose') {
      // Full-body ×3 + cardio ×2 + optional lower
      weekly = [
        { key: 'rest',  title: 'Active recovery', focus: 'walk + mobility', moves: ['zone2','pallof','plank'], kind: 'endurance' },
        { key: 'fbA',   title: 'Full-body A · Strength preserve', focus: 'compound lifts + core', moves: ['squat','bench','row','ohp','plank'], kind: 'strength' },
        { key: 'card',  title: 'Cardio · Zone 2',                 focus: '45 min steady',        moves: ['zone2'], kind: 'cardio' },
        { key: 'fbB',   title: 'Full-body B · Hypertrophy',       focus: 'volume + burn',        moves: ['rdl','incline','cableRow','bulgarian','pallof'], kind: 'hyper' },
        { key: 'hiit',  title: 'HIIT + core',                     focus: 'metabolic finisher',   moves: ['hiit','plank','hanging'], kind: 'cardio' },
        { key: 'lower', title: 'Lower · Glute-focus',             focus: 'strength + burn',      moves: ['hipThrust','bulgarian','rdl','legCurl','calf'], kind: 'hyper' },
        { key: 'rest',  title: 'Rest', focus: 'full off day', moves: [], kind: 'rest' }
      ];
    } else {
      // Maintenance: 3 full-body + cardio
      weekly = [
        { key: 'rest',  title: 'Rest', focus: 'off', moves: [], kind: 'rest' },
        { key: 'fbA',   title: 'Full-body A', focus: 'strength base', moves: ['squat','bench','row','ohp'], kind: 'strength' },
        { key: 'card',  title: 'Cardio + core', focus: 'general health', moves: ['zone2','plank','pallof'], kind: 'cardio' },
        { key: 'fbB',   title: 'Full-body B', focus: 'hypertrophy', moves: ['deadlift','incline','pullup','bulgarian'], kind: 'hyper' },
        { key: 'rest',  title: 'Active recovery', focus: 'walk', moves: ['zone2'], kind: 'endurance' },
        { key: 'fbC',   title: 'Full-body C', focus: 'mixed', moves: ['rdl','dbShoulder','cableRow','legExt','hanging'], kind: 'hyper' },
        { key: 'rest',  title: 'Rest', focus: 'off', moves: [], kind: 'rest' }
      ];
    }

    const today = weekly[day];
    // Deload check: if we're 3+ weeks in with steady progress, deload every 4th week
    const weekNum = Math.floor((new Date(getTodayStr()) - new Date(m.joinDate || m.progress[0]?.date)) / 86400000 / 7);
    const isDeload = weekNum > 0 && weekNum % 4 === 0 && today.kind !== 'rest' && today.kind !== 'cardio';

    // Adherence-based nudge
    const adherenceNudge = s.adherenceDrop > 1 ? `Your training's cooled off (${s.sessions7} sessions this week vs ${s.sessionsPerWeek}/wk avg). Two-thirds effort today beats zero.` : null;

    // If plateau detected on a hypertrophy goal, prescribe a change
    const plateauNote = s.plateau && g !== 'maintain' ? `Trend's flat two weeks running — good week to add 5 kg on your top set, or a 4th accessory move.` : null;

    if (today.kind === 'rest') {
      return {
        title: today.title,
        focus: today.focus,
        exercises: [{ name: 'Walk outdoors', prescription: '20–40 min · easy pace' }, { name: 'Foam roll + mobility', prescription: '10 min · problem areas' }],
        warmup: 'Skip — this is a recovery day.',
        finisher: '',
        injuryNote: null,
        coachMessage: rand([
          `Rest day. Recovery is when adaptation happens — don't skip it.`,
          `Off day today. Aim for 7–9 h sleep tonight, hydrate, walk if you feel restless.`,
          `Deliberate rest. Your ${s.sessionsPerWeek} sessions/wk is where growth compounds — trust the deload.`
        ]),
        adherenceNudge, plateauNote, isDeload: false,
        estMinutes: 30, restDay: true, kind: today.kind
      };
    }

    let rx = buildSession(today.title, today.focus, today.moves, today.kind, m, m.injuries);

    // Deload = drop volume 40%, keep intensity moderate
    if (isDeload) {
      rx = rx.map((r) => ({ ...r, prescription: r.prescription.replace(/(\d+)×/, (mm, n) => Math.max(2, Math.ceil(n * 0.6)) + '×') + ' · DELOAD week' }));
    }

    // Injury note surfaced separately
    const injuryNote = (m.injuries || []).length
      ? `Working around: ${m.injuries.join(', ')}. Swaps applied automatically — see the exercise list.`
      : null;

    const trend = s.weightDelta > 0 ? `up ${s.weightDelta.toFixed(1)} kg` : `down ${Math.abs(s.weightDelta).toFixed(1)} kg`;
    const goalMessages = {
      gain: [
        `You're ${trend} in ${s.days} days — ${round(s.perWeek, 2)} kg/wk, right in the lean-bulk zone. Today's ${today.focus} keeps volume in the MAV range.`,
        `${s.goalDone}% to ${m.targetWeight} kg. Push the first compound to RPE 8 — that's where hypertrophy lives. Rest 2–3 min between big sets.`,
        `Body's growing at ${round(s.perWeek, 2)} kg/wk. Today: ${today.focus}. Compound first, accessories second, done in ~65 min.`
      ],
      lose: [
        `${trend} — ${round(Math.abs(s.perWeek), 2)} kg/wk trend, ${s.projDate ? 'projected at ' + m.targetWeight + ' kg by ' + s.projDate : 'on track'}. Today's ${today.focus} preserves strength while in a deficit.`,
        `${s.goalDone}% to ${m.targetWeight} kg. Intensity high, volume manageable — recovery is limited when kcal is low.`,
        `Consistency's paying off. Today leans on ${today.focus}. Chase the target, not soreness.`
      ],
      maintain: [
        `Steady state. Today keeps the base strong via ${today.focus} without overreaching.`,
        `Happy where you are. Session quality > session volume this month.`,
        `Movement quality focus today. Feel each rep.`
      ]
    };

    return {
      title: today.title,
      focus: today.focus,
      exercises: rx,
      injuryNote,
      warmup: 'Foam roll problem areas 3 min · dynamic mobility 5 min · 2 warm-up sets at 40 % and 60 % of your working weight.',
      finisher: g === 'lose' ? 'Optional: 10 min incline walk finisher.' : g === 'gain' ? 'Optional: 8 min zone 2 for recovery.' : '',
      coachMessage: rand(goalMessages[g]) + (isDeload ? ' — **Deload week** — 60% volume, 100% intensity.' : ''),
      adherenceNudge, plateauNote, isDeload,
      estMinutes: today.kind === 'cardio' ? 45 : today.kind === 'strength' ? 65 : 55,
      restDay: false, kind: today.kind
    };
  }

  // ============================ ANALYST ====================================
  function progressNarrative(m) {
    const s = stats(m);
    const g = m.goal || 'maintain';
    const p = m.progress || [];
    if (p.length < 2) return { headline: 'Not enough data yet.', paragraphs: ['Log another entry to see your first trend.'], milestones: [], goalPct: '—' };

    const dir = g === 'gain' ? 'up' : 'down';
    const good = (g === 'gain' && s.perWeek > 0.05) || (g === 'lose' && s.perWeek < -0.05);
    const goalPct = s.goalDone != null ? `${s.goalDone}%` : '—';

    // Headline based on trend + projection
    let headline;
    if (s.plateau) {
      headline = `Weight's held at ${s.last.weight.toFixed(1)} kg for two weeks. That's not failure — it's a plateau. Time to nudge one variable.`;
    } else if (good && s.projDate) {
      headline = `${dir === 'up' ? '+' : ''}${s.weightDelta.toFixed(1)} kg over ${s.days} days · trending ${round(Math.abs(s.perWeek), 2)} kg/wk · **projected ${m.targetWeight} kg by ${s.projDate}**.`;
    } else if (good) {
      headline = `${dir === 'up' ? '+' : ''}${s.weightDelta.toFixed(1)} kg in ${s.days} days — you're ${goalPct} to ${m.targetWeight} kg.`;
    } else {
      headline = `Trend's ${Math.abs(s.perWeek) < 0.05 ? 'flat' : 'wrong direction'}. Log a couple more entries; one measurement is noise, three is signal.`;
    }

    // Consistency phrase
    const consistency = s.sessionsPerWeek >= 5 ? 'top-tier' : s.sessionsPerWeek >= 4 ? 'strong' : s.sessionsPerWeek >= 3 ? 'steady' : 'light';
    const consistencyPhrase = {
      'top-tier': `You're training ${s.sessionsPerWeek}× a week — top 20% of members. Recovery matters more than another session.`,
      'strong': `${s.sessionsPerWeek} sessions/wk — well above average. Add a mobility day if joints feel tight.`,
      'steady': `${s.sessionsPerWeek} sessions/wk is a healthy rhythm. One more session/wk would compound quickly.`,
      'light': `${s.sessionsPerWeek} sessions/wk is your rhythm. A 4th session next week would meaningfully accelerate results.`
    }[consistency];

    // Body composition line — Navy BF if we could compute
    const bfLine = s.bf != null
      ? `Estimated body fat (Navy circumference): **${s.bf.toFixed(1)}%** · lean mass ${s.lbm.toFixed(1)} kg. ${g === 'lose' ? 'If waist keeps trending down while weight stalls, you are recomping — LBM is what matters, not the scale.' : ''}`
      : '';

    // Waist / measurement changes
    const waistLine = Math.abs(s.waistDelta) >= 1
      ? `Your waist is ${s.waistDelta < 0 ? 'down' : 'up'} ${Math.abs(s.waistDelta).toFixed(1)} cm since baseline${g === 'lose' ? ' — a better fat-loss signal than the scale.' : g === 'gain' ? ' — expected in a bulk; monitor to keep the ratio clean.' : '.'}`
      : `Waist is stable at ${s.last.waist} cm.`;

    const bmiPhrase = s.bmi ? (s.bmi < 18.5 ? ' BMI reads underweight — the plan is set up to bring it into range.' : s.bmi < 25 ? ' BMI reads healthy.' : s.bmi < 30 ? ' BMI reads overweight — that will move with time and consistency.' : ' BMI reads high; steady losses are the safest route.') : '';

    // Pace safety
    let paceWarning = '';
    if (s.paceTooFastLoss) paceWarning = ` **Fast for a cut** — >1%/wk of bodyweight is where muscle loss starts. Reduce your deficit ~150 kcal.`;
    if (s.paceTooFastGain) paceWarning = ` **Fast for a bulk** — >0.5%/wk starts adding fat over muscle. Ease surplus ~150 kcal.`;

    // Adherence
    const adherence = s.adherenceDrop > 1 ? ` Your training's dropped from ${s.sessionsPerWeek}/wk to ${s.sessions7} this week — worth watching before it becomes a habit.` : '';

    // Next step — projection or plateau intervention
    const nextStep = s.plateau
      ? `**Plateau intervention:** drop 100 kcal (protein stays), add one 20-min walk, hold two weeks, re-measure.`
      : s.projDate
      ? `At your current pace (${round(Math.abs(s.perWeek), 2)} kg/wk), you'll hit ${m.targetWeight} kg around **${s.projDate}**. Stay the course.`
      : `Log 2–3 more entries and I can project a target date.`;

    const paragraphs = [
      `${headline} ${consistencyPhrase}${adherence}`,
      `${waistLine}${bmiPhrase} ${bfLine}${paceWarning}`,
      nextStep
    ];

    // Milestones
    const milestones = [];
    if (Math.abs(s.weightDelta) >= 5) milestones.push(`${Math.abs(s.weightDelta) >= 10 ? 'Huge' : 'Big'}: ${round(Math.abs(s.weightDelta), 1)} kg ${s.weightDelta < 0 ? 'down' : 'up'} since ${p[0].date}`);
    if (Math.abs(s.waistDelta) >= 5) milestones.push(`Waist ${s.waistDelta < 0 ? '−' : '+'}${round(Math.abs(s.waistDelta), 1)} cm since baseline`);
    if (s.goalDone != null && s.goalDone >= 75 && s.goalDone < 100) milestones.push(`${s.goalDone}% to target — final push`);
    if (s.goalDone != null && s.goalDone >= 100) milestones.push(`🎯 Target reached! Time to set the next goal.`);
    if (s.sessionsPerWeek >= 4 && !s.plateau) milestones.push(`${s.sessionsPerWeek}/wk sessions maintained`);
    if (p.length >= 6) milestones.push(`${p.length} progress logs — data discipline unlocked`);

    return { headline, paragraphs, milestones, goalPct };
  }

  // ============================ CHAT — expanded coverage ===================
  const getFirstName = (m) => m.firstName || (m.full_name ? m.full_name.split(' ')[0] : (m.name ? m.name.split(' ')[0] : 'there'));
  const CHAT_RULES = [
    // ---- greetings ----
    { match: /^\s*(hi|hello|hey|salam|assalam|hola|hii?)\b/i, agents: ['assistant'], reply: (m) => `Hey ${getFirstName(m)} 👋 — what's on your mind today? Ask about training, food, or how you're tracking against your goal.` },

    // ---- workout / programming ----
    { match: /workout|exercise|routine|training|session|program(?!er)/i, agents: ['coach'], reply: (m) => {
      const w = coachWorkout(m);
      if (w.restDay) return `Today's a **rest day**. Body's rebuilding — 30–40 min walk if you want to stay moving.`;
      return `Today's plan: **${w.title}** — ${w.focus}. ~${w.estMinutes} min including warm-up. First move: **${w.exercises[0].name}** (${w.exercises[0].prescription}). Full breakdown on the Workout tab.`;
    }},
    { match: /skip|rest|off day|recover|too tired|fatigued|sore/i, agents: ['coach'], reply: (m) => {
      const s = stats(m);
      if (s.sessionsPerWeek >= 5) return `You've done ${s.sessionsPerWeek} sessions/wk lately — a rest day is the right call. Sleep 8h, walk 30 min, protein on point.`;
      if (s.sessions7 === 0) return `You haven't trained in a week. If it's genuine fatigue take today, but tomorrow's session moves you back on track. Missed weeks compound.`;
      return `Rest is programmed, not earned. If you're beat, take it — one skipped session won't derail you.`;
    }},
    { match: /deload|deloading/i, agents: ['coach'], reply: (m) => `Deload = 60% volume, 100% intensity, one week every 4–6 weeks. Your CNS resets and you come back stronger. Skipping it is how you get injured or plateau.` },
    { match: /progressive overload|add weight|increase|heavier/i, agents: ['coach'], reply: (m) => `Progressive overload rule I use: last session was RPE ≤7 → add 2.5 kg or 1 rep. RPE 8 → hold weight, add a rep. RPE 9+ → hold, focus on form, deload if it repeats.` },
    { match: /rpe|reps in reserve|rir/i, agents: ['coach'], reply: (m) => `RPE 10 = failure. RPE 9 = 1 rep in reserve. RPE 8 = 2 RIR (hypertrophy sweet spot). RPE 7 = warm-up / technique. Use RPE 8 for accessories, 8–9 for compounds.` },
    { match: /warm.?up|prep session/i, agents: ['coach'], reply: () => `Warm-up: 5 min mobility + specific ramp — empty bar × 5, 40% × 5, 60% × 3, 80% × 1, then working sets. Never skip the 40% set; that's where you groove the pattern.` },
    { match: /cardio|hiit|zone.?2|running/i, agents: ['coach'], reply: (m) => {
      if (m.goal === 'lose') return `Cardio supports a cut; it doesn't create one — the deficit does. 2× 30–45 min Zone 2 + 1× HIIT is plenty alongside lifting.`;
      if (m.goal === 'gain') return `Keep cardio to 1–2 short Zone 2 sessions/wk. More than that starts eating into your surplus and recovery.`;
      return `2–3× 30 min moderate cardio maintains heart health. Mix modalities so it doesn't get stale.`;
    }},
    { match: /form|technique|posture|proper|how to (do|perform)/i, agents: ['coach'], reply: (m) => `Universal cues: brace hard (belly out into a belt), bar path stays vertical (or over mid-foot), keep breathing through the concentric. Film a side-angle set of 5 — you'll spot 90% of issues yourself.` },
    { match: /injury|hurt|pain|knee|shoulder|back|elbow|wrist/i, agents: ['coach'], reply: (m, q) => {
      const which = (q.match(/knee|shoulder|back|elbow|wrist/i) || [''])[0].toLowerCase();
      const swaps = { knee: 'leg press, RDL, seated leg extension', shoulder: 'incline DB, cable row, DB shoulder press', back: 'RDL, chest-supported row, front squat', elbow: 'neutral-grip pulls, hammer curl, close-grip variations', wrist: 'thumbless grip, straps, dumbbell variants' };
      const swap = swaps[which] || 'joint-friendly alternatives';
      return `Sharp = stop. Dull = modify. For a ${which || 'joint'} issue, swap to ${swap} and drop load 20–30%. If not better in 3–4 days, get a physio to look. Don't train through sharp pain — it never gets better on its own.`;
    }},

    // ---- nutrition / macros ----
    { match: /calor|kcal|energy intake|energy expend/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      return `Today: **${n.calories} kcal**. Method: ${n.method}. TDEE ≈ ${n.tdee} kcal · BMR ≈ ${n.bmr} kcal. ${m.goal === 'gain' ? `Surplus of ${n.calories - n.tdee}.` : m.goal === 'lose' ? `Deficit of ${n.tdee - n.calories}.` : 'Maintenance.'}`;
    }},
    { match: /macro|protein|carb|fat|split/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      return `Today's macros: **${n.protein} g protein · ${n.carbs} g carbs · ${n.fat} g fat · ${n.fiber} g fibre**. Split into 4 meals of ~${n.proteinPerMeal} g protein each — Aragon & Schoenfeld showed even distribution maxes out MPS.`;
    }},
    { match: /how much protein|protein.*(kg|per|need)/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      const s = stats(m);
      const w = s.last ? s.last.weight : 70;
      return `Protein target: **${n.protein} g** = ${round(n.protein / w, 1)} g/kg. ${m.goal === 'lose' ? 'Aggressive cut → 2.3–3.1 g/kg lean mass, per Helms 2014.' : m.goal === 'gain' ? '1.6–2.0 g/kg BW is the muscle-building sweet spot.' : '1.6 g/kg keeps LBM stable.'} Miss a meal? Add a whey shake.`;
    }},
    { match: /meal|what.*(eat|food)|sample|breakfast|lunch|dinner/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      const meal = n.meals[Math.floor(Math.random() * n.meals.length)];
      return `Sample **${meal.name}** (~${meal.kcal} kcal): ${meal.items.join('; ')}. Full day on the Nutrition tab.`;
    }},
    { match: /water|hydrat|drink|dehydr/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      return `Aim **${n.hydration} L today** — 35 ml/kg baseline + 500 ml per hour of training. Sip during the session; don't chug at the end.`;
    }},
    { match: /supplement|whey|creatine|multivit|omega|caffeine|pre.?workout|fish oil/i, agents: ['nutritionist'], reply: (m) => {
      const n = nutritionPlan(m);
      return `For your goal I'd run this stack: ${n.supplements.map((s) => `**${s}**`).join(', ')}. Everything else is a nice-to-have. Skip anything with a "proprietary blend."`;
    }},
    { match: /refeed|cheat|reset day|diet break/i, agents: ['nutritionist'], reply: (m) => {
      if (m.goal !== 'lose') return `Refeeds are a cutting tool. Not really relevant in a bulk or maintenance.`;
      return `Once a week, take calories back to maintenance with an extra 100–150 g carbs. Helps leptin, thyroid, and training performance. Not a cheat day — a planned tool.`;
    }},
    { match: /vegetarian|vegan|meat|halal|dairy/i, agents: ['nutritionist'], reply: (m) => {
      if (m.vegetarian) return `You're already logged as vegetarian — the sample day swaps chicken/fish for paneer, dal, tofu. Hit ${nutritionPlan(m).protein} g protein by combining sources.`;
      return `You're logged as omnivorous. Want me to switch you to vegetarian sample days? Ask reception to update the profile.`;
    }},
    { match: /alcohol|drinking|beer|wine/i, agents: ['nutritionist'], reply: () => `Alcohol = ~7 kcal/g, no nutritional value, impairs recovery + muscle protein synthesis for ~24h. In a cut it's expensive kcal; in a bulk it steals recovery. Occasional social drink is fine — don't build it in daily.` },

    // ---- progress / analysis ----
    { match: /progress|weight|scale|kg|track|going/i, agents: ['analyst'], reply: (m) => {
      const n = progressNarrative(m);
      return n.paragraphs.slice(0, 2).join(' ');
    }},
    { match: /plateau|stuck|not.*(losing|gaining|change)|no progress/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      if (!s.plateau) return `You're not in a plateau — trend is ${round(s.perWeek, 2)} kg/wk. Give a change 2 full weeks before calling it stuck.`;
      return `Plateau confirmed (${round(s.days, 0)} days flat). Intervention: drop 100 kcal (protein stays), add one walk/wk, hold 2 weeks. Don't slash more — that trashes recovery.`;
    }},
    { match: /goal|target|when|how long|projection|deadline/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      if (!s.projDate) return `Not enough trend yet — log 2–3 more entries and I'll project a target date.`;
      return `At your current trend (${round(Math.abs(s.perWeek), 2)} kg/wk), you'll hit ${m.targetWeight} kg around **${s.projDate}**. Stay the course.`;
    }},
    { match: /bmi/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      if (s.bmi == null) return `Need weight + height to compute BMI — one of them is missing.`;
      const cat = s.bmi < 18.5 ? 'underweight' : s.bmi < 25 ? 'healthy' : s.bmi < 30 ? 'overweight' : 'obese';
      return `Your BMI is **${s.bmi.toFixed(1)}** (${cat}). BMI is a crude measure — it doesn't distinguish muscle from fat. If you're lifting, waist + progress photos beat BMI as a signal.`;
    }},
    { match: /body fat|bf%|composition|lean mass/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      if (s.bf == null) return `I need waist measurement to estimate body fat (US Navy method). Once you log a waist number I can give you a %, ± 3%.`;
      return `Estimated body fat: **${s.bf.toFixed(1)}%** (US Navy circumference method, ± 3%). Lean mass ≈ ${s.lbm.toFixed(1)} kg. For most people, tracking waist ↓ + lean mass steady in a cut is a better signal than the scale alone.`;
    }},
    { match: /waist|belt|belly/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      if (!s.first) return `Log a waist measurement and I can trend it for you.`;
      return `Waist is ${s.waistDelta < 0 ? 'down' : s.waistDelta > 0 ? 'up' : 'flat at'} ${s.waistDelta < 0 ? '' : '+' }${round(s.waistDelta, 1)} cm since baseline. ${m.goal === 'lose' ? 'For fat loss, waist is a cleaner signal than the scale — muscle + water shift.' : ''}`;
    }},

    // ---- lifestyle / recovery ----
    { match: /sleep|insomnia|rest at night|going to bed/i, agents: ['analyst'], reply: () => `Recovery lives and dies by sleep. 7–9 h in a dark room, no screens 30 min before bed, caffeine cut-off 8 h before sleep. If you're grinding on 5 h and expecting gains, no macros or program will save you.` },
    { match: /stress|anxious|anxiety|burn.?out/i, agents: ['analyst'], reply: () => `Chronic stress raises cortisol, blunts recovery, and drives appetite. Two proven tools: 5 min box breathing (4-4-4-4) daily, and 20 min walking outside without your phone. Both free. Both work.` },
    { match: /step|walking|neat/i, agents: ['coach'], reply: (m) => {
      const s = stats(m);
      if (m.goal === 'lose') return `Aim 8–10 k steps/day — cheap TDEE bump without eating into recovery. On rest days, walking is the workout.`;
      return `Baseline 6–8 k steps/day keeps NEAT healthy. Steps don't compete with lifting — go higher if it fits your schedule.`;
    }},

    // ---- trainer / support ----
    { match: /trainer|coach.*(assign|contact)|book (a )?session|human/i, agents: ['assistant'], reply: (m) => {
      return `Your assigned trainer is **${m.trainer}**. They see the same data I do — book a session at reception, or DM the front desk for an in-person form check.`;
    }},
    { match: /schedule|book|appointment/i, agents: ['assistant'], reply: (m) => `Session slots run Mon–Sat 6 am–10 pm. Ask reception to book with ${m.trainer}. In future this will be a one-tap booking in the app.` },
    { match: /price|fee|cost|pay|payment/i, agents: ['assistant'], reply: (m) => `Your monthly fee is Rs ${(m.monthlyFee || 0).toLocaleString('en-PK')} on the ${m.pkg} tier${m.ptf ? ` + Rs ${(m.ptf).toLocaleString('en-PK')} PTF` : ''}. Next due: ${m.nextDue}. Pay at reception or JazzCash — receipt in the app.` },

    // ---- philosophical / motivational ----
    { match: /motivat|discipline|show up|don.*t want/i, agents: ['assistant'], reply: (m) => `Motivation is unreliable. Discipline is a schedule. On days you don't feel it, the deal is: change into gym clothes, show up, do 40% of the session. You'll usually do 100% once you start.` },
    { match: /why|purpose|worth it/i, agents: ['analyst'], reply: (m) => {
      const s = stats(m);
      return `You've shown up for ${s.sessionsPerWeek} sessions/wk for ${round(s.days / 7, 0)} weeks and moved ${Math.abs(s.weightDelta).toFixed(1)} kg toward your goal. That's the "why". Compound weeks turn into transformed years.`;
    }},

    // ---- edge cases ----
    { match: /joke|funny/i, agents: ['assistant'], reply: () => `Why did the powerlifter cross the road? He didn't — his hips didn't hinge that far. Now go do your workout.` },
    { match: /thank|thanks|shukria/i, agents: ['assistant'], reply: (m) => `Anytime ${getFirstName(m)} — that's what we're here for.` }
  ];

  function chat(agent, question, m) {
    const q = (question || '').trim();
    if (!q) return { text: 'Ask me anything.', agent };
    // First: strict match with current agent
    let hit = CHAT_RULES.find((r) => r.match.test(q) && (agent === 'any' || r.agents.includes(agent)));
    // Fall back: any rule that matches the question, regardless of agent
    if (!hit) hit = CHAT_RULES.find((r) => r.match.test(q));
    if (hit) return { text: hit.reply(m, q), agent: hit.agents[0] };

    // Contextual honest fallback — flags this question as something a real
    // LLM would answer better, but tries to give useful direction.
    const s = stats(m);
    const generics = [
      `I don't have a rule for that one — but based on your context (${m.goal} goal, ${s.last ? s.last.weight.toFixed(1) + ' kg' : '?'}, ${s.sessionsPerWeek}/wk sessions), the honest answer is usually: consistency > perfection. Can you rephrase with more specifics?`,
      `That's outside my canned playbook. Want me to route it to your trainer ${m.trainer} for a human take?`,
      `Interesting question. Give me more context — which lift, which meal, which day — and I'll give a real answer.`
    ];
    return { text: rand(generics), agent };
  }

  // Suggested prompts per agent (pre-populate chat)
  const SUGGESTIONS = {
    coach:        ['What\'s today\'s workout?', 'Can I skip today?', 'How do I know when to add weight?', 'My knee is sore — swaps?', 'Should I deload?'],
    nutritionist: ['What are my macros today?', 'Give me a sample meal', 'How much water?', 'Are supplements worth it?', 'How much protein per kg?'],
    analyst:      ['How\'s my progress?', 'When will I hit my target?', 'Am I in a plateau?', 'What\'s my body fat?', 'Is my waist changing?'],
    assistant:    ['Hi', 'Book a session with my trainer', 'How much do I pay?', 'Explain my BMI', 'Motivate me']
  };

  // ============================ ACHIEVEMENTS + STREAK ======================
  // Detects milestones from the member's data + logs. Returns unlocked +
  // in-progress achievements, plus an active streak count.
  //
  // The badge library is a mix of "first-of" (First workout, First PR) and
  // "consistency" (7-day streak, 30-day streak, 12-session month) and
  // "outcome" (5 kg down, waist −5 cm, target reached).
  function achievements(m, sessionLogs, mealLogs) {
    const p = (m.progress || []).slice();
    const first = p[0], last = p[p.length - 1];
    const weightDelta = first && last ? last.weight - first.weight : 0;
    const waistDelta = first && last ? last.waist - first.waist : 0;
    const goalDone = m.targetWeight != null && first && last
      ? Math.round(((last.weight - first.weight) / (m.targetWeight - first.weight)) * 100)
      : 0;

    sessionLogs = sessionLogs || [];
    mealLogs = mealLogs || [];

    // Streak: consecutive days with at least one session OR meal logged
    // OR — for the seeded case — count the member's `sessions` field.
    const streak = computeStreak(m, sessionLogs, mealLogs);

    // Total sessions ever: sessionLogs + built-in sessions
    const totalSessions = sessionLogs.length + (m.sessions || []).length;

    const BADGE_LIB = [
      // First-of
      { id: 'first-workout',   icon: '🥇', title: 'First workout',       desc: 'Logged your first session',  hit: totalSessions >= 1,  need: 1 - totalSessions },
      { id: 'first-pr',        icon: '💥', title: 'First PR',            desc: 'Any RPE ≥ 9 rep counts',     hit: sessionLogs.some(l => l.entries?.some(e => (e.sets || []).some(s => s.rpe >= 9))) },
      { id: 'first-log',       icon: '📝', title: 'First progress log',  desc: 'Logged your first weight',   hit: p.length >= 1 },
      // Consistency
      { id: 'streak-7',        icon: '🔥', title: '7-day streak',        desc: '7 days in a row logging/training', hit: streak.days >= 7,   need: 7 - streak.days,   progress: Math.min(100, streak.days / 7 * 100) },
      { id: 'streak-14',       icon: '🔥', title: '14-day streak',       desc: 'Two weeks unbroken',         hit: streak.days >= 14,  need: 14 - streak.days,  progress: Math.min(100, streak.days / 14 * 100) },
      { id: 'streak-30',       icon: '🏆', title: '30-day streak',       desc: 'A month of habit',           hit: streak.days >= 30,  need: 30 - streak.days,  progress: Math.min(100, streak.days / 30 * 100) },
      { id: 'sessions-10',     icon: '💪', title: '10 sessions',         desc: 'Volume adds up',             hit: totalSessions >= 10, need: 10 - totalSessions, progress: Math.min(100, totalSessions * 10) },
      { id: 'sessions-50',     icon: '💯', title: '50 sessions',         desc: 'Serious commitment',         hit: totalSessions >= 50, need: 50 - totalSessions, progress: Math.min(100, totalSessions * 2) },
      { id: 'sessions-100',    icon: '🎯', title: '100 sessions',        desc: 'The Century',                hit: totalSessions >= 100, need: 100 - totalSessions, progress: Math.min(100, totalSessions) },
      { id: 'logs-6',          icon: '📊', title: '6 weight logs',       desc: 'Data discipline',            hit: p.length >= 6,        need: 6 - p.length, progress: Math.min(100, p.length / 6 * 100) },
      // Outcome
      { id: 'weight-5',        icon: '⚖️', title: '5 kg moved',          desc: '5 kg in either direction',   hit: Math.abs(weightDelta) >= 5, progress: Math.min(100, Math.abs(weightDelta) / 5 * 100) },
      { id: 'weight-10',       icon: '🎖', title: '10 kg moved',         desc: 'The double digits',          hit: Math.abs(weightDelta) >= 10, progress: Math.min(100, Math.abs(weightDelta) / 10 * 100) },
      { id: 'waist-5',         icon: '📏', title: 'Waist −5 cm',         desc: 'Body composition winning',   hit: waistDelta <= -5, progress: Math.min(100, Math.max(0, -waistDelta) / 5 * 100) },
      { id: 'goal-half',       icon: '🎽', title: '50% of the way',      desc: 'Halfway to your target',     hit: goalDone >= 50, progress: Math.min(100, goalDone) },
      { id: 'goal-reached',    icon: '🏅', title: 'Target reached',      desc: 'Time for the next goal',     hit: goalDone >= 100, progress: Math.min(100, goalDone) },
      // Class-based
      { id: 'first-class',     icon: '🎟', title: 'First class booked',  desc: 'Try something new',          hit: false /* set by caller */ }
    ];

    const unlocked = BADGE_LIB.filter((b) => b.hit);
    const inProgress = BADGE_LIB.filter((b) => !b.hit && b.progress != null && b.progress > 0)
      .sort((a, b) => (b.progress || 0) - (a.progress || 0))
      .slice(0, 4);
    const upcoming = BADGE_LIB.filter((b) => !b.hit && (b.progress == null || b.progress === 0)).slice(0, 3);

    return { unlocked, inProgress, upcoming, streak, totalSessions };
  }

  function computeStreak(m, sessionLogs, mealLogs) {
    // Combine any log dates from sessionLogs + mealLogs + m.sessions
    const dates = new Set();
    (sessionLogs || []).forEach((l) => dates.add(l.date));
    (mealLogs || []).forEach((l) => dates.add(l.date));
    (m.sessions || []).forEach((l) => dates.add(l.date));
    (m.progress || []).forEach((l) => dates.add(l.date));

    let streak = 0;
    const today = new Date(getTodayStr());
    // Walk back day-by-day
    for (let i = 0; i < 90; i++) {
      const d = new Date(today);
      d.setDate(today.getDate() - i);
      const dStr = d.toISOString().slice(0, 10);
      if (dates.has(dStr)) streak++;
      else if (i === 0) continue; // today might not have a log yet — allow grace
      else break;
    }
    return { days: streak, lastDay: today.toISOString().slice(0, 10) };
  }

  // ============================ MEAL LOG + FEEDBACK ========================
  // Meal log entry: { date, name, kcal, protein, carbs, fat }
  // Analyzes the day against the member's macro target and gives feedback.
  function analyzeMealDay(m, mealsToday) {
    const target = macros(m);
    const totals = mealsToday.reduce((acc, meal) => ({
      kcal: acc.kcal + (Number(meal.kcal) || 0),
      protein: acc.protein + (Number(meal.protein) || 0),
      carbs: acc.carbs + (Number(meal.carbs) || 0),
      fat: acc.fat + (Number(meal.fat) || 0)
    }), { kcal: 0, protein: 0, carbs: 0, fat: 0 });

    const remaining = {
      kcal: target.calories - totals.kcal,
      protein: target.protein - totals.protein,
      carbs: target.carbs - totals.carbs,
      fat: target.fat - totals.fat
    };
    const pctKcal = Math.round((totals.kcal / target.calories) * 100);
    const pctProtein = Math.round((totals.protein / target.protein) * 100);

    // Feedback string based on where in the day + remaining protein
    let msg;
    const hour = new Date().getHours();
    if (mealsToday.length === 0) msg = `No meals logged yet today. Aim for **${target.protein} g protein** by end of day.`;
    else if (pctKcal < 50 && hour > 18) msg = `You're only at ${pctKcal}% of today's calories and it's past dinner time. Add a shake + fruit before bed — being under-fed is worse than perfect macros.`;
    else if (pctKcal > 110) msg = `${pctKcal}% of calories already — worth stopping here. One over-day doesn't undo the week.`;
    else if (remaining.protein > 30 && hour > 18) msg = `You have **${remaining.protein} g protein** to go and it's late. A 40 g whey shake now fixes it — miss that and you underfeed muscle repair overnight.`;
    else if (remaining.protein <= 10 && pctKcal < 90) msg = `Protein target already met. Whatever else you eat, don't cut carbs — you need them for tomorrow's training.`;
    else if (pctKcal >= 80 && pctProtein >= 90) msg = `On track — ${pctKcal}% kcal, ${pctProtein}% protein. This is what a clean day looks like.`;
    else msg = `${totals.kcal}/${target.calories} kcal · ${totals.protein}/${target.protein} g protein. ${remaining.kcal > 0 ? `${remaining.kcal} kcal room left.` : `Over by ${-remaining.kcal} kcal.`}`;

    // Score: 40 % kcal proximity, 60 % protein hit
    const kcalScore = Math.round(40 * Math.min(1, 1 - Math.abs(pctKcal - 100) / 100));
    const proteinScore = Math.round(60 * Math.min(1, pctProtein / 100));
    const score = kcalScore + proteinScore;

    return { target, totals, remaining, pctKcal, pctProtein, feedback: msg, score };
  }

  // ============================ POST-SESSION ANALYSIS ======================
  // Called after a member logs a completed workout. Reads each set's actual
  // weight/reps/RPE vs the prescription and returns per-exercise adjustments
  // for next session plus an overall feedback paragraph.
  //
  // Progressive-overload rule (based on Helms/Israetel autoregulation):
  //   RPE ≤ 7 → +2.5 kg or +1 rep next session
  //   RPE = 8  → hold weight, add reps
  //   RPE ≥ 9 → hold, focus on form; deload if it repeats
  function analyzeSession(m, log) {
    // log = { date, title, entries: [{ name, prescription, sets: [{ weight, reps, rpe }] }] }
    if (!log || !log.entries || !log.entries.length) return { feedback: 'Nothing to analyze.', adjustments: [], score: 0 };

    const adjustments = [];
    let totalRPE = 0, setsCount = 0;
    let completedCount = 0;
    let skippedCount = 0;

    for (const ex of log.entries) {
      const sets = (ex.sets || []).filter((s) => s.weight != null || s.reps != null);
      if (!sets.length) { skippedCount++; continue; }
      completedCount++;
      const topRpe = Math.max(...sets.map((s) => s.rpe || 7));
      const avgRpe = sets.reduce((a, s) => a + (s.rpe || 7), 0) / sets.length;
      totalRPE += avgRpe;
      setsCount += sets.length;

      // Adjustment rule
      let rec, tag;
      if (topRpe <= 7) {
        rec = 'Add 2.5 kg or +1 rep across the board next session';
        tag = 'progress';
      } else if (topRpe === 8) {
        rec = 'Hold weight, aim for +1 rep across working sets';
        tag = 'hold';
      } else if (topRpe === 9) {
        rec = 'Hold weight next session; if RPE 9 repeats, deload 10 %';
        tag = 'caution';
      } else {
        rec = 'Deload — drop weight 10 % next session';
        tag = 'deload';
      }
      adjustments.push({ name: ex.name, topRpe, avgRpe: round(avgRpe, 1), rec, tag });
    }

    const avgSessionRpe = setsCount ? totalRPE / completedCount : 0;
    // 0-100 session quality score based on completion + RPE band
    const completionScore = Math.round((completedCount / Math.max(1, completedCount + skippedCount)) * 60);
    const rpeScore = avgSessionRpe > 0 && avgSessionRpe <= 8.5 ? 40 : avgSessionRpe > 8.5 ? 30 : 25;
    const score = completionScore + rpeScore;

    // Feedback paragraph — personal, specific, actionable
    const parts = [];
    if (skippedCount > 0) parts.push(`You skipped **${skippedCount}** exercise${skippedCount > 1 ? 's' : ''}${skippedCount > completedCount ? ' — worth logging even a light set next time.' : '.'}`);
    if (adjustments.filter((a) => a.tag === 'progress').length >= 2) {
      parts.push(`Multiple lifts finished at RPE ≤ 7 — you're primed to add weight next session. Nice work leaving reps in the tank.`);
    } else if (adjustments.filter((a) => a.tag === 'caution' || a.tag === 'deload').length >= 2) {
      parts.push(`A couple of lifts hit RPE 9+. If that pattern repeats next session, treat it as a deload signal — 60 % volume, hold weight, come back sharp.`);
    } else if (avgSessionRpe >= 7.5 && avgSessionRpe <= 8.5) {
      parts.push(`Session RPE averaged ${round(avgSessionRpe, 1)} — right in the hypertrophy sweet spot. This is what productive weeks look like.`);
    } else if (avgSessionRpe < 7) {
      parts.push(`Session averaged RPE ${round(avgSessionRpe, 1)} — light. If nothing's hurting, next session should feel heavier.`);
    }
    // Adherence to plan
    if (completedCount === log.entries.length && skippedCount === 0) parts.push(`**Full completion** — every prescribed exercise logged. That discipline compounds.`);

    return {
      score,
      avgRpe: round(avgSessionRpe, 1),
      completedCount,
      skippedCount,
      adjustments,
      feedback: parts.join(' ') || `Session logged. Come back tomorrow and I'll adapt from here.`,
      logDate: log.date,
      logTitle: log.title
    };
  }

  // ============================ WEEKLY DIGEST ==============================
  // Sunday recap the member gets. Reads the last 7 days of sessions + weight
  // + macros hit rate and generates a coherent 3-paragraph summary + next-
  // week preview.
  function weeklyDigest(m, sessionLogs) {
    sessionLogs = sessionLogs || [];
    const todayMs = new Date(getTodayStr()).getTime();
    const wk = 7 * 86400000;
    const thisWeek = sessionLogs.filter((s) => todayMs - new Date(s.date).getTime() <= wk);
    const priorWeek = sessionLogs.filter((s) => {
      const d = todayMs - new Date(s.date).getTime();
      return d > wk && d <= 2 * wk;
    });

    const s = stats(m);
    const p = m.progress || [];
    // Weight change this week
    const nowCutoff = new Date(todayMs - wk).toISOString().slice(0, 10);
    const weekStart = p.filter((r) => r.date <= nowCutoff).pop();
    const weekEnd = p[p.length - 1] || null;
    const weekDelta = (weekStart && weekEnd) ? weekEnd.weight - weekStart.weight : 0;

    // Average session quality
    const scores = thisWeek.map((l) => (analyzeSession(m, l).score)).filter(Boolean);
    const avgScore = scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null;

    // Session count trend
    const sessionCountDelta = thisWeek.length - priorWeek.length;

    // Story lines
    const lines = [];

    // Opening: session count + delta
    if (thisWeek.length === 0) {
      lines.push(`No logged sessions this week. If life happened, that's fine — just log one next week to keep the streak alive.`);
    } else {
      lines.push(`**${thisWeek.length} session${thisWeek.length > 1 ? 's' : ''}** logged this week${sessionCountDelta > 0 ? ` (+${sessionCountDelta} vs last week)` : sessionCountDelta < 0 ? ` (${sessionCountDelta} vs last week)` : ' — matching last week'}.${avgScore ? ` Average session quality score: **${avgScore}/100**.` : ''}`);
    }

    // Weight movement
    if (weekStart && weekEnd) {
      const dir = weekDelta > 0 ? 'up' : weekDelta < 0 ? 'down' : 'flat';
      const good = (m.goal === 'gain' && weekDelta > 0) || (m.goal === 'lose' && weekDelta < 0);
      lines.push(`Weight ${dir === 'flat' ? 'held' : dir} **${Math.abs(weekDelta).toFixed(1)} kg** vs a week ago${good ? ' — that\'s in the direction of your goal.' : m.goal !== 'maintain' ? ' — one week is noise; the 4-week trend is signal.' : '.'}`);
    }

    // Next week directive
    let nextWeek;
    if (thisWeek.length >= 4 && avgScore && avgScore >= 75) {
      nextWeek = `**Next week:** you've earned the progression. Add 2.5 kg on top sets or +1 rep on accessories. Same split.`;
    } else if (thisWeek.length <= 1) {
      nextWeek = `**Next week:** just get 3 sessions logged. Any weight, any intensity — the habit before the intensity.`;
    } else if (s.plateau) {
      nextWeek = `**Next week:** plateau intervention — 100 kcal below your current target, one extra 20-min walk, hold two weeks.`;
    } else {
      nextWeek = `**Next week:** ${m.goal === 'gain' ? 'push volume on the compound of the day — extra top set if RPE feels ≤ 7.' : m.goal === 'lose' ? 'protein floor stays. If waist held flat, add 500 more steps a day.' : 'keep the rhythm; introduce one new mobility drill.'}`;
    }
    lines.push(nextWeek);

    // Wins / milestones
    const wins = [];
    if (thisWeek.length >= (priorWeek.length + 1) && priorWeek.length > 0) wins.push(`+${thisWeek.length - priorWeek.length} session vs last week`);
    if (avgScore && avgScore >= 85) wins.push(`Top-tier session quality (${avgScore}/100)`);
    if (Math.abs(weekDelta) >= 0.5) wins.push(`Weight moved ${weekDelta > 0 ? '+' : ''}${weekDelta.toFixed(1)} kg`);
    if (s.plateau && thisWeek.length >= 3) wins.push(`Held training frequency through a plateau — that's the muscle-preserving move`);

    return {
      thisWeekSessions: thisWeek.length,
      priorWeekSessions: priorWeek.length,
      sessionCountDelta,
      avgScore,
      weekDelta,
      paragraphs: lines,
      wins,
      periodLabel: 'This week',
      sessionLogs: thisWeek
    };
  }

  return {
    stats, calories, macros,
    coachWorkout, nutritionPlan, progressNarrative,
    analyzeSession, weeklyDigest, analyzeMealDay,
    achievements, computeStreak,
    chat, SUGGESTIONS,
    // Expose helpers so app.js can render extra detail
    navyBodyFat, leanMass, linreg, rolling,
    agents: {
      coach:        { name: 'Coach',        emoji: '💪', tag: 'Programming + technique',  color: '#c9a24a' },
      nutritionist: { name: 'Nutritionist', emoji: '🥗', tag: 'Macros, meals, hydration', color: '#63c66d' },
      analyst:      { name: 'Analyst',      emoji: '📈', tag: 'Progress + projections',   color: '#7ab6e2' },
      assistant:    { name: 'Assistant',    emoji: '💬', tag: 'Anything else',            color: '#a19d92' }
    }
  };
})();
