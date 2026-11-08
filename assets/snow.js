(() => {
  const KEY = 'ski-snow-v1';
  const TTL = 36e5;
  const API = 'https://api.open-meteo.com/v1/forecast';
  const DOW = ['S', 'M', 'T', 'O', 'T', 'F', 'L'];
  const MONTHS = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const cm = v => String(Math.round(v * 10) / 10).replace('.', ',');
  const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } };
  const write = v => { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} };
  const parts = (date, tz) => new Intl.DateTimeFormat('sv-SE', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' }).formatToParts(date).reduce((o, p) => (o[p.type] = p.value, o), {});

  const fetchSpot = (cfg, spot) => {
    const q = new URLSearchParams({
      latitude: cfg.lat, longitude: cfg.lon, elevation: spot.elevation,
      hourly: 'snow_depth,snowfall,temperature_2m', daily: 'snowfall_sum',
      past_days: 7, forecast_days: 7, timezone: 'Europe/Vienna'
    });
    return fetch(API + '?' + q).then(r => r.json().then(j => {
      if (!r.ok || !j.hourly) throw new Error(`Fejl ${r.status}: ${j.reason || 'ukendt svar'}`);
      return j;
    }));
  };

  const build = (cfg, res) => {
    const p = parts(new Date(), 'Europe/Vienna');
    const today = `${p.year}-${p.month}-${p.day}`;
    const nowHour = `${today}T${p.hour}:00`;
    const spots = res.map((j, i) => {
      const h = j.hourly;
      let idx = h.time.indexOf(nowHour);
      if (idx < 0) idx = Math.max(0, h.time.filter(t => t <= nowHour).length - 1);
      let depth = null;
      for (let k = idx; k >= 0 && depth == null; k--) depth = h.snow_depth[k];
      return { label: cfg.spots[i].label, elevation: cfg.spots[i].elevation, depth: Math.round((depth || 0) * 100), temp: Math.round(h.temperature_2m[idx] || 0), falling: (h.snowfall[idx] || 0) > 0 };
    });
    const days = res[0].daily.time.map((d, k) => ({ d, cm: Math.round((res[0].daily.snowfall_sum[k] || 0) * 10) / 10 }));
    const sum = list => Math.round(list.reduce((a, x) => a + x.cm, 0));
    const last = days.filter(x => x.d <= today && x.cm >= 0.5).pop() || null;
    return { spots, days, today, past7: sum(days.filter(x => x.d < today)), next7: sum(days.filter(x => x.d >= today)), last };
  };

  const ago = (d, today) => {
    const diff = Math.round((Date.parse(today) - Date.parse(d)) / 864e5);
    return diff === 0 ? 'i dag' : diff === 1 ? 'i går' : `for ${diff} dage siden`;
  };

  const render = (cfg, s) => {
    let h = '<div class="trip-spots">';
    s.spots.forEach(sp => {
      h += `<div class="trip-spot"><span class="trip-spot-l">${esc(sp.label)} <small>${sp.elevation.toLocaleString('da-DK')} m</small></span><span class="trip-spot-v">${sp.depth}<small> cm</small></span><span class="trip-spot-s">${sp.depth > 0 ? 'snedybde' : 'ingen sne'} · ${sp.temp}°${sp.falling ? ' · sner nu' : ''}</span></div>`;
    });
    h += '</div>';
    const max = Math.max(1, ...s.days.map(x => x.cm));
    h += `<div class="trip-bars" style="--n: ${s.days.length}">`;
    s.days.forEach(x => {
      const dt = new Date(x.d + 'T12:00:00');
      const cls = x.d === s.today ? ' now' : x.d > s.today ? ' fc' : '';
      h += `<span class="trip-bar${cls}" title="${dt.getDate()}. ${MONTHS[dt.getMonth()]}: ${cm(x.cm)} cm"><i style="--h: ${Math.round(x.cm / max * 100)}%"></i><small>${DOW[dt.getDay()]}</small></span>`;
    });
    h += '</div>';
    h += `<div class="trip-meta"><span class="trip-chip">${cfg.icon}${s.past7} cm sidste 7 dage</span><span class="trip-chip">${s.next7} cm ventet næste 7 dage</span></div>`;
    const last = s.last ? `Sidst faldet ${ago(s.last.d, s.today)} (${cm(s.last.cm)} cm)` : 'Ingen nysne den seneste uge';
    h += `<p class="trip-last muted small">${esc(last)} · bjerget, modelberegnet</p>`;
    return h;
  };

  const fail = (box, msg) => {
    if (box.querySelector('.trip-spots')) return;
    box.innerHTML = `<div class="wx-empty"><p class="muted small">Snedata kunne ikke hentes lige nu.</p><p class="muted small">${esc(msg)}</p></div>`;
  };

  const load = () => {
    const box = document.querySelector('[data-snow]');
    if (!box || box.dataset.busy) return;
    let cfg;
    try { cfg = JSON.parse(box.dataset.snow); } catch (e) { return; }
    const cached = read();
    if (cached && cached.d) box.innerHTML = render(cfg, cached.d);
    if (cached && Date.now() - cached.t < TTL) return;
    box.dataset.busy = '1';
    Promise.all(cfg.spots.map(sp => fetchSpot(cfg, sp)))
      .then(res => { const d = build(cfg, res); write({ t: Date.now(), d }); box.innerHTML = render(cfg, d); })
      .catch(err => fail(box, err && err.message ? err.message : 'Netværksfejl'))
      .finally(() => { delete box.dataset.busy; });
  };

  document.addEventListener('page:ready', load);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
