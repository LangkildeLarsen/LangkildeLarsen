(() => {
  const csrf = document.body.dataset.csrf;
  const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };
  const fmt1 = v => v.toFixed(1).replace('.', ',');

  const tick = () => {
    const box = document.querySelector('[data-trip-start]');
    if (!box) return;
    const left = Number(box.dataset.tripStart) * 1000 - Date.now();
    const d = box.querySelector('[data-trip-d]');
    const hm = box.querySelector('[data-trip-hm]');
    if (!d || !hm || left <= 0) return;
    const days = Math.floor(left / 864e5);
    d.textContent = days;
    d.nextElementSibling.textContent = days === 1 ? ' dag' : ' dage';
    hm.textContent = `${Math.floor(left % 864e5 / 36e5)} t ${Math.floor(left % 36e5 / 6e4)} min ${String(Math.floor(left % 6e4 / 1e3)).padStart(2, '0')} s`;
  };
  tick();
  setInterval(tick, 1000);

  document.addEventListener('click', e => {
    const copy = e.target.closest('[data-copy]');
    if (copy) {
      const input = copy.parentElement.querySelector('[data-copy-src]');
      const done = () => { const t = copy.lastChild.textContent; copy.lastChild.textContent = ' Kopieret'; setTimeout(() => { copy.lastChild.textContent = t; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done).catch(() => { input.select(); });
      else { input.select(); document.execCommand('copy'); done(); }
    }
    const cam = e.target.closest('[data-cam]');
    if (cam) {
      document.querySelectorAll('[data-cam]').forEach(b => b.classList.toggle('on', b === cam));
      const frame = document.querySelector('[data-cam-frame]');
      frame.src = cam.dataset.cam;
      frame.title = 'Webcam ' + cam.dataset.camName;
      const open = document.querySelector('[data-cam-open]');
      open.href = cam.dataset.cam;
      open.textContent = 'Åbn ' + cam.dataset.camName + ' i nyt vindue';
    }
  });

  let busy = false;
  const refresh = (focusAction) => {
    if (busy) return Promise.resolve();
    busy = true;
    return fetch('./', { credentials: 'same-origin', headers: { 'X-Requested-With': 'swap' } })
      .then(r => r.ok ? r.text() : Promise.reject())
      .then(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('[data-live]').forEach(fresh => {
          const old = document.querySelector(`[data-live="${fresh.dataset.live}"]`);
          if (!old) return;
          const openDetails = [...old.querySelectorAll('details')].map(d => d.open);
          fresh.classList.add('swapped');
          old.replaceWith(fresh);
          fresh.querySelectorAll('details').forEach((d, i) => { if (openDetails[i]) d.open = true; });
        });
        if (focusAction) {
          const input = document.querySelector(`form [name="action"][value="${focusAction}"]`)?.form.querySelector('input[name="text"], input[name="title"]');
          if (input) input.focus({ preventScroll: true });
        }
      })
      .catch(() => {})
      .finally(() => { busy = false; });
  };

  document.addEventListener('submit', e => {
    const form = e.target.closest('form[data-ajax]');
    if (!form) return;
    e.preventDefault();
    if (form.hasAttribute('data-confirm') && !confirm('Er du sikker?')) return;
    const data = new FormData(form);
    const action = data.get('action');
    form.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const li = form.closest('li');
    if (li && /toggle$/.test(action)) li.classList.toggle('done');
    fetch('./', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(r => r.ok ? r : Promise.reject())
      .then(() => refresh(/_add$/.test(action) ? action : ''))
      .catch(() => { form.querySelectorAll('button').forEach(b => { b.disabled = false; }); alert('Det kunne ikke gemmes. Tjek forbindelsen.'); });
  });

  setInterval(() => {
    if (document.hidden) return;
    const a = document.activeElement;
    if (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName) && a.value) return;
    if (document.querySelector('details[open] form input:not([type=hidden]):not([type=date])')?.value) return;
    refresh();
  }, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

  const root = document.querySelector('[data-tracker]');
  if (!root || !('geolocation' in navigator)) {
    if (root) root.querySelector('[data-gps]').textContent = 'GPS ikke tilgængelig';
    return;
  }
  const $ = s => root.querySelector(s);
  const KEY = 'ski-track';
  const today = () => new Date().toLocaleDateString('sv-SE');
  let st = store.get(KEY);
  let watch = null;
  let lock = null;
  let syncTimer = null;
  let nowSpeed = 0;

  const fresh = () => ({ id: 'g-' + today() + '-' + Math.random().toString(36).slice(2, 8), date: today(), m: 0, max: 0, desc: 0, ms: 0, active: false, last: null, altRef: null });
  if (!st || st.date !== today()) st = fresh();

  const gps = t => { $('[data-gps]').textContent = t; };
  const render = () => {
    $('[data-t-speed]').textContent = Math.round(nowSpeed);
    $('[data-t-max]').textContent = fmt1(st.max);
    $('[data-t-km]').textContent = fmt1(st.m / 1000);
    $('[data-t-desc]').textContent = Math.round(st.desc);
    const mins = Math.floor(st.ms / 6e4);
    $('[data-t-time]').textContent = `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, '0')}`;
    const btn = $('[data-t-toggle]');
    btn.classList.toggle('on', st.active);
    btn.querySelector('span').textContent = st.active ? 'Stop tracking' : (st.m > 0 ? 'Fortsæt tracking' : 'Start tracking');
    root.classList.toggle('running', st.active);
  };

  const payload = live => {
    const f = new FormData();
    f.append('csrf', csrf);
    f.append('action', 'run_save');
    f.append('id', st.id);
    f.append('date', st.date);
    f.append('km', (st.m / 1000).toFixed(2));
    f.append('max', st.max.toFixed(1));
    f.append('descent', Math.round(st.desc));
    f.append('mins', Math.round(st.ms / 6e4));
    f.append('src', 'gps');
    f.append('live', live ? '1' : '');
    return f;
  };
  const sync = live => {
    if (st.m < 20) return Promise.resolve();
    return fetch('./', { method: 'POST', body: payload(live), credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(r => { if (r.ok) { st.synced = Date.now(); store.set(KEY, st); } })
      .catch(() => {});
  };

  const dist = (a, b) => {
    const R = 6371e3, r = Math.PI / 180;
    const dLat = (b.lat - a.lat) * r, dLon = (b.lon - a.lon) * r;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
  };

  const onPos = pos => {
    const c = pos.coords;
    const p = { lat: c.latitude, lon: c.longitude, alt: c.altitude, t: pos.timestamp };
    if (c.accuracy > 30) { gps(`Svagt signal (±${Math.round(c.accuracy)} m)`); return; }
    gps(`GPS ±${Math.round(c.accuracy)} m`);
    if (p.alt != null && (c.altitudeAccuracy == null || c.altitudeAccuracy <= 25)) {
      if (st.altRef == null) st.altRef = p.alt;
      else if (p.alt < st.altRef - 5) { st.desc += st.altRef - p.alt; st.altRef = p.alt; }
      else if (p.alt > st.altRef + 5) st.altRef = p.alt;
    }
    const last = st.last;
    if (!last || p.t - last.t > 120000) { st.last = p; store.set(KEY, st); return; }
    const dt = (p.t - last.t) / 1000;
    if (dt <= 0) return;
    const d = dist(last, p);
    const spd = c.speed != null && c.speed >= 0 ? c.speed : d / dt;
    if (spd > 42) return;
    if (d < Math.max(3, c.accuracy * 0.6) && spd < 1.2) { nowSpeed = 0; render(); return; }
    const climb = p.alt != null && last.alt != null ? (p.alt - last.alt) / dt : null;
    const onLift = climb != null && climb > 0.4;
    nowSpeed = spd * 3.6;
    if (!onLift) {
      st.m += d;
      st.ms += dt * 1000;
      if (c.accuracy <= 20 && nowSpeed > st.max) st.max = Math.min(nowSpeed, 150);
    }
    st.last = p;
    store.set(KEY, st);
    render();
  };
  const onErr = err => {
    if (err.code === 1) { gps('Adgang til placering afvist'); stop(); }
    else gps('Søger efter GPS…');
  };

  const wake = async () => {
    try { if ('wakeLock' in navigator && !lock) { lock = await navigator.wakeLock.request('screen'); lock.addEventListener('release', () => { lock = null; }); } } catch (e) {}
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden && st.active) wake(); });

  const start = () => {
    if (st.date !== today()) st = fresh();
    st.active = true;
    st.last = null;
    gps('Søger efter GPS…');
    watch = navigator.geolocation.watchPosition(onPos, onErr, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
    wake();
    clearInterval(syncTimer);
    syncTimer = setInterval(() => sync(true), 60000);
    store.set(KEY, st);
    render();
  };
  function stop() {
    if (watch != null) navigator.geolocation.clearWatch(watch);
    watch = null;
    clearInterval(syncTimer);
    if (lock) { lock.release().catch(() => {}); lock = null; }
    st.active = false;
    st.last = null;
    nowSpeed = 0;
    gps('GPS slukket');
    store.set(KEY, st);
    render();
    sync(false).then(() => refresh());
  }

  $('[data-t-toggle]').addEventListener('click', () => { st.active ? stop() : start(); });
  addEventListener('pagehide', () => {
    if (st.active && st.m >= 20 && navigator.sendBeacon) navigator.sendBeacon('./', payload(true));
  });

  render();
  if (st.active) start();
})();

(() => {
  const box = document.querySelector('[data-install]');
  if (!box) return;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = navigator.standalone === true || matchMedia('(display-mode: standalone)').matches;
  let closed = false;
  try { closed = localStorage.getItem('ski-install-x') === '1'; } catch (e) {}
  if (ios && !standalone && (!closed || box.hasAttribute('data-welcome'))) box.hidden = false;
  box.querySelector('[data-install-x]').addEventListener('click', () => {
    box.hidden = true;
    try { localStorage.setItem('ski-install-x', '1'); } catch (e) {}
  });
})();

document.addEventListener('click', e => {
  const b = e.target.closest('[data-copy-text]');
  if (!b) return;
  const t = b.dataset.copyText;
  const ok = () => { b.classList.add('copied'); setTimeout(() => b.classList.remove('copied'), 1600); };
  if (navigator.clipboard) navigator.clipboard.writeText(t).then(ok).catch(() => prompt('Kopiér linket', t));
  else prompt('Kopiér linket', t);
});

(() => {
  const nav = document.querySelector('[data-tabs]');
  if (!nav) return;
  const mq = matchMedia('(max-width: 900px)');
  const valid = ['ski', 'lister', 'penge', 'rejsen'];
  const set = (tab, scroll) => {
    if (!valid.includes(tab)) return;
    document.body.dataset.tab = tab;
    nav.querySelectorAll('[data-tab-go]').forEach(a => a.classList.toggle('active', a.dataset.tabGo === tab));
    try { localStorage.setItem('ski-tab', tab); } catch (e) {}
    if (scroll && mq.matches) scrollTo({ top: 0, behavior: 'instant' });
  };
  let saved = null;
  try { saved = localStorage.getItem('ski-tab'); } catch (e) {}
  set(saved || document.body.dataset.tab, false);
  nav.addEventListener('click', e => {
    const a = e.target.closest('[data-tab-go]');
    if (!a || !mq.matches) return;
    e.preventDefault();
    set(a.dataset.tabGo, true);
  });
})();
