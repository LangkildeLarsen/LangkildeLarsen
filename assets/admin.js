(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
  };

  const nav = document.querySelector('.rail-nav');
  const pill = nav && nav.querySelector('.rail-pill');
  const navLinks = nav ? [...nav.querySelectorAll('a')] : [];

  const placePill = (el, animate) => {
    if (!pill) return;
    if (!el) { pill.style.opacity = '0'; return; }
    pill.style.transition = animate && !reduce ? '' : 'none';
    pill.style.width = el.offsetWidth + 'px';
    pill.style.height = el.offsetHeight + 'px';
    pill.style.transform = `translate(${el.offsetLeft}px, ${el.offsetTop}px)`;
    pill.style.opacity = '1';
    if (!animate) pill.getBoundingClientRect();
  };

  const setActive = el => {
    navLinks.forEach(a => a.classList.toggle('active', a === el));
    placePill(el, true);
  };

  if (nav) {
    nav.classList.add('has-pill');
    placePill(nav.querySelector('a.active'), false);
    let t;
    addEventListener('resize', () => {
      clearTimeout(t);
      t = setTimeout(() => placePill(nav.querySelector('a.active'), false), 80);
    });
  }

  const isAdminUrl = url => url.origin === location.origin && url.pathname.replace(/index\.php$/, '') === location.pathname.replace(/index\.php$/, '');

  const cache = new Map();
  const getPage = url => fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'swap' } })
    .then(async r => ({ ok: r.ok, url: r.url, html: await r.text() }));
  const prefetch = url => {
    const hit = cache.get(url);
    if (hit && Date.now() - hit.t < 15000) return hit.p;
    const p = getPage(url).catch(() => null);
    cache.set(url, { t: Date.now(), p });
    return p;
  };

  const swap = async (url, init, push = true) => {
    document.documentElement.classList.add('loading');
    let res;
    try {
      if (init && init.method === 'POST') {
        cache.clear();
        const r = await fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'X-Requested-With': 'swap' } }, init));
        res = { ok: r.ok, url: r.url, html: await r.text() };
      } else {
        res = await prefetch(url);
        cache.delete(url);
        if (!res) throw new Error('fetch');
      }
    } catch (e) {
      location.href = url;
      return;
    }
    const html = res.html;
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fresh = doc.querySelector('main.content');
    const current = document.querySelector('main.content');
    if (!res.ok || !fresh || !current) {
      location.href = res.url || url;
      return;
    }
    current.replaceWith(fresh);
    document.title = doc.title;
    const freshActive = doc.querySelector('.rail-nav a.active');
    const label = freshActive && freshActive.getAttribute('aria-label');
    setActive(navLinks.find(a => a.getAttribute('aria-label') === label) || null);
    const finalUrl = new URL(res.url);
    const hash = new URL(url, location.href).hash;
    if (push) history.pushState({ swap: true }, '', finalUrl.pathname + finalUrl.search + hash);
    document.documentElement.classList.remove('loading');
    const target = hash && document.querySelector(hash);
    if (target) target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
    else if (push) scrollTo({ top: 0, behavior: 'auto' });
    fresh.querySelectorAll('.card, .hack-card').forEach(el => { el.style.animation = 'none'; el.offsetHeight; el.style.animation = ''; });
    document.dispatchEvent(new CustomEvent('page:ready'));
  };
  window.__swap = swap;

  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target === '_blank' || a.hasAttribute('download') || a.dataset.reload !== undefined) return;
    const url = new URL(a.href, location.href);
    if (!isAdminUrl(url)) return;
    if (url.search === location.search && url.hash) return;
    e.preventDefault();
    if (navLinks.includes(a)) setActive(a);
    swap(url.href);
  });

  document.addEventListener('submit', e => {
    const form = e.target;
    if (e.defaultPrevented || form.dataset.reload !== undefined || form.closest('[data-reload]')) return;
    const action = new URL(form.getAttribute('action') || location.href, location.href);
    if (!isAdminUrl(action)) return;
    e.preventDefault();
    const method = (form.getAttribute('method') || 'get').toLowerCase();
    let data;
    try { data = new FormData(form, e.submitter); } catch (err) { data = new FormData(form); if (e.submitter && e.submitter.name) data.append(e.submitter.name, e.submitter.value); }
    if (method === 'get') {
      action.search = new URLSearchParams(data).toString();
      swap(action.href);
    } else {
      swap(action.href, { method: 'POST', body: data });
    }
  });

  addEventListener('popstate', () => swap(location.href, null, false));

  const warm = e => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target === '_blank') return;
    const url = new URL(a.href, location.href);
    if (isAdminUrl(url) && url.search !== location.search) prefetch(url.href);
  };
  document.addEventListener('pointerover', warm, { passive: true });
  document.addEventListener('touchstart', warm, { passive: true });

  const myVersion = document.documentElement.dataset.version || '';
  const toast = document.getElementById('update-toast');
  const checkVersion = async () => {
    if (!myVersion || !toast || !toast.hidden) return;
    try {
      const r = await fetch('index.php?ajax=version', { credentials: 'same-origin', cache: 'no-store' });
      const j = await r.json();
      if (j.v && j.v !== myVersion) {
        toast.hidden = false;
        requestAnimationFrame(() => toast.classList.add('show'));
      }
    } catch (e) {}
  };
  setInterval(() => { if (!document.hidden) checkVersion(); }, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) checkVersion(); });
  document.addEventListener('click', e => {
    if (e.target.closest('[data-update]')) {
      try { window.__saveMusic && window.__saveMusic(); } catch (x) {}
      location.reload();
    }
  });

  const escH = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const fmtText = t => escH(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');

  const setUnread = n => {
    document.querySelectorAll('[data-unread-dot]').forEach(d => { d.hidden = !n; });
  };
  const pollUnread = async () => {
    try {
      const r = await fetch('index.php?ajax=unread', { credentials: 'same-origin' });
      const j = await r.json();
      setUnread(j.count);
    } catch (e) {}
  };
  setInterval(() => { if (!document.hidden) pollUnread(); }, 30000);

  document.addEventListener('click', e => {
    const bell = e.target.closest('[data-bell]');
    const pop = document.querySelector('.notif-pop');
    if (bell && pop) {
      pop.hidden = !pop.hidden;
      bell.setAttribute('aria-expanded', String(!pop.hidden));
      return;
    }
    if (pop && !pop.hidden && !e.target.closest('.notif-pop')) pop.hidden = true;
  });

  let dmTimer = null;
  let dmLast = '';
  const initDm = () => {
    clearInterval(dmTimer);
    const thread = document.querySelector('[data-thread]');
    if (!thread) return;
    const list = document.getElementById('dm-list');
    const form = thread.querySelector('[data-dm-form]');
    const scrollDown = () => { list.scrollTop = list.scrollHeight; };
    scrollDown();
    const load = async () => {
      try {
        const r = await fetch('index.php?ajax=thread&with=' + encodeURIComponent(thread.dataset.thread), { credentials: 'same-origin' });
        const msgs = await r.json();
        const sig = msgs.length + ':' + (msgs.length ? msgs[msgs.length - 1].id : '');
        if (sig === dmLast) return;
        const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
        dmLast = sig;
        let day = null;
        list.innerHTML = msgs.map(m => {
          let h = '';
          if (m.day !== day) { day = m.day; h += '<p class="dm-day">' + escH(m.label) + '</p>'; }
          h += '<div class="bubble' + (m.mine ? ' mine' : '') + (m.type === 'request' ? ' request' : '') + '">' + (m.type === 'request' ? '<span class="req-tag">Ønske via Jarvis</span>' : '') + '<p>' + fmtText(m.text) + '</p><time>' + escH(m.time) + '</time></div>';
          return h;
        }).join('');
        if (atBottom) scrollDown();
        pollUnread();
      } catch (e) {}
    };
    dmLast = '';
    load();
    dmTimer = setInterval(() => { if (!document.hidden && document.body.contains(thread)) load(); else if (!document.body.contains(thread)) clearInterval(dmTimer); }, 5000);
    form.addEventListener('submit', async e => {
      e.preventDefault();
      e.stopPropagation();
      const input = form.querySelector('input[name=text]');
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      list.insertAdjacentHTML('beforeend', '<div class="bubble mine sending"><p>' + fmtText(text) + '</p></div>');
      scrollDown();
      const fd = new FormData(form);
      fd.set('text', text);
      try { await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'json' } }); } catch (x) {}
      dmLast = '';
      load();
    });
  };

  const voice = {
    on: store.get('voice-on', false),
    name: store.get('voice-name', ''),
    rate: store.get('voice-rate', 1),
    pitch: store.get('voice-pitch', 1),
  };
  const synth = window.speechSynthesis;
  const pickVoice = () => {
    if (!synth) return null;
    const vs = synth.getVoices();
    return vs.find(v => v.name === voice.name) || vs.find(v => /^da/i.test(v.lang)) || null;
  };
  const jvText = { idle: ['Klar', '<span>Klar.</span> Skriv eller tal til mig.'], listening: ['Lytter', '<span>Lytter…</span>'], thinking: ['Tænker', '<span>Tænker…</span> slår op i dine data'], speaking: ['Taler', '<span>Svarer…</span>'] };
  const jvSet = st => {
    const jv = document.querySelector('[data-jv]');
    if (!jv) return;
    jv.dataset.s = st;
    jv.querySelector('[data-jv-label]').textContent = jvText[st][0];
    jv.querySelector('[data-jv-log]').innerHTML = jvText[st][1];
  };
  const speak = text => {
    if (!synth || !voice.on) { jvSet('idle'); return; }
    synth.cancel();
    const u = new SpeechSynthesisUtterance(text.replace(/\*\*/g, ''));
    u.onstart = () => jvSet('speaking');
    u.onend = u.onerror = () => { if (!synth.speaking) jvSet('idle'); };
    const v = pickVoice();
    if (v) u.voice = v;
    u.lang = v ? v.lang : 'da-DK';
    u.rate = voice.rate;
    u.pitch = voice.pitch;
    synth.speak(u);
  };

  const initChat = () => {
    const chat = document.querySelector('[data-chat]');
    if (!chat) return;
    const list = document.getElementById('chat-list');
    const form = chat.querySelector('[data-chat-form]');
    const input = form.querySelector('input[name=text]');
    const mic = form.querySelector('[data-mic]');
    const toggle = chat.querySelector('[data-voice-toggle]');
    const pop = chat.querySelector('.voice-pop');
    const sel = chat.querySelector('[data-voice-select]');
    const rate = chat.querySelector('[data-voice-rate]');
    const pitch = chat.querySelector('[data-voice-pitch]');
    const scrollDown = () => { list.scrollTop = list.scrollHeight; };
    scrollDown();

    const paintToggle = () => {
      toggle.classList.toggle('on', voice.on);
      toggle.setAttribute('aria-pressed', String(voice.on));
      chat.classList.toggle('voice-on', voice.on);
    };
    paintToggle();
    toggle.addEventListener('click', () => { voice.on = !voice.on; store.set('voice-on', voice.on); if (!voice.on && synth) { synth.cancel(); jvSet('idle'); } paintToggle(); });

    const fillVoices = () => {
      if (!synth) return;
      const vs = synth.getVoices().filter(v => /^(da|en|sv|nb|no)/i.test(v.lang)).sort((a, b) => (/^da/i.test(b.lang) - /^da/i.test(a.lang)) || a.name.localeCompare(b.name));
      sel.innerHTML = '<option value="">Standard (dansk)</option>' + vs.map(v => '<option value="' + escH(v.name) + '"' + (v.name === voice.name ? ' selected' : '') + '>' + escH(v.name) + ' · ' + escH(v.lang) + '</option>').join('');
    };
    fillVoices();
    if (synth) synth.onvoiceschanged = fillVoices;
    rate.value = voice.rate;
    pitch.value = voice.pitch;
    chat.querySelector('[data-voice-settings]').addEventListener('click', () => { pop.hidden = !pop.hidden; });
    sel.addEventListener('change', () => { voice.name = sel.value; store.set('voice-name', voice.name); });
    rate.addEventListener('input', () => { voice.rate = Number(rate.value); store.set('voice-rate', voice.rate); });
    pitch.addEventListener('input', () => { voice.pitch = Number(pitch.value); store.set('voice-pitch', voice.pitch); });
    chat.querySelector('[data-voice-test]').addEventListener('click', () => { const was = voice.on; voice.on = true; speak('Hej ' + (document.querySelector('.me')?.getAttribute('aria-label') || '').split(' ')[0] + ', sådan lyder jeg.'); voice.on = was; });

    chat.querySelectorAll('[data-q]').forEach(b => b.addEventListener('click', () => {
      input.value = b.dataset.q;
      input.focus();
      if (!b.dataset.q.endsWith(': ')) form.requestSubmit();
    }));

    const clearForm = chat.querySelector('.card-head form');
    const note = chat.querySelector('[data-chat-note]');
    let noteT = null;
    if (clearForm) clearForm.addEventListener('submit', async e => {
      e.preventDefault();
      e.stopPropagation();
      const r = await fetch('index.php', { method: 'POST', body: new FormData(clearForm), credentials: 'same-origin', headers: { 'X-Requested-With': 'json' } }).then(x => x.json()).catch(() => null);
      if (!r || !r.ok) return;
      if (synth) synth.cancel();
      jvSet('idle');
      list.innerHTML = '';
      if (note) {
        clearTimeout(noteT);
        note.textContent = 'Samtalen er ryddet';
        note.hidden = false;
        note.classList.remove('out');
        noteT = setTimeout(() => { note.classList.add('out'); noteT = setTimeout(() => { note.hidden = true; }, 400); }, 2000);
      }
      input.focus();
    });

    let busy = false;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      e.stopPropagation();
      const text = input.value.trim();
      if (!text || busy) return;
      busy = true;
      input.value = '';
      chat.querySelector('.chat-hello')?.remove();
      list.insertAdjacentHTML('beforeend', '<div class="bubble mine"><p>' + fmtText(text) + '</p></div><div class="bubble ai typing"><p><i></i><i></i><i></i></p></div>');
      scrollDown();
      jvSet('thinking');
      const fd = new FormData(form);
      fd.set('text', text);
      let j;
      try {
        const r = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'json' } });
        j = await r.json();
      } catch (x) {
        j = { error: 'Kunne ikke få forbindelse. Prøv igen.' };
      }
      list.querySelector('.typing')?.remove();
      const msg = j.error || j.reply || '';
      list.insertAdjacentHTML('beforeend', '<div class="bubble ai' + (j.error ? ' err' : '') + '"><p>' + fmtText(msg) + '</p></div>');
      scrollDown();
      busy = false;
      if (!j.error) speak(msg);
      else jvSet('idle');
      input.focus();
    });

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { mic.hidden = true; return; }
    let rec = null;
    mic.addEventListener('click', () => {
      if (rec) { rec.stop(); return; }
      rec = new SR();
      rec.lang = 'da-DK';
      rec.interimResults = true;
      rec.continuous = false;
      let finalText = '';
      mic.classList.add('live');
      if (synth) synth.cancel();
      jvSet('listening');
      rec.onresult = ev => {
        let interim = '';
        for (let i = ev.resultIndex; i < ev.results.length; i++) {
          if (ev.results[i].isFinal) finalText += ev.results[i][0].transcript;
          else interim += ev.results[i][0].transcript;
        }
        input.value = (finalText + interim).trim();
      };
      rec.onerror = () => {};
      rec.onend = () => {
        mic.classList.remove('live');
        rec = null;
        if (!busy) jvSet('idle');
        if (input.value.trim()) {
          if (!voice.on) { voice.on = true; store.set('voice-on', true); paintToggle(); }
          form.requestSubmit();
        }
      };
      rec.start();
    });
  };

  const initHome = () => {
    const card = document.querySelector('[data-home-jarvis]');
    if (!card) return;
    const form = card.querySelector('[data-hj-form]');
    const input = form.querySelector('input[name=text]');
    const say = card.querySelector('[data-hj-say]');
    let busy = false;
    const ask = async text => {
      if (!text || busy) return;
      busy = true;
      input.value = '';
      input.placeholder = text;
      say.classList.remove('reply', 'err');
      say.textContent = 'Tænker…';
      jvSet('thinking');
      const fd = new FormData(form);
      fd.set('text', text);
      let j;
      try {
        const r = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'json' } });
        j = await r.json();
      } catch (x) {
        j = { error: 'Kunne ikke få forbindelse. Prøv igen.' };
      }
      busy = false;
      input.placeholder = 'Spørg Jarvis om noget…';
      say.innerHTML = fmtText(j.error || j.reply || '');
      say.classList.add(j.error ? 'err' : 'reply');
      if (j.error) jvSet('idle'); else speak(j.reply || '');
      if (j.changed) {
        fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'swap' } }).then(r => r.text()).then(html => {
          const doc = new DOMParser().parseFromString(html, 'text/html');
          ['.c-train', '.c-rem', '.c-watch', '.c-cal'].forEach(sel => {
            const a = document.querySelector('.dash ' + sel), b = doc.querySelector('.dash ' + sel);
            if (a && b) a.replaceWith(b);
          });
          document.dispatchEvent(new CustomEvent('cards:updated'));
        }).catch(() => {});
      }
    };
    form.addEventListener('submit', e => { e.preventDefault(); e.stopPropagation(); ask(input.value.trim()); });
    card.querySelectorAll('[data-hj-q]').forEach(b => b.addEventListener('click', () => {
      const q = b.dataset.hjQ;
      if (/træne/.test(q)) { input.value = 'Jeg har trænet i dag: '; input.focus(); return; }
      ask(q);
    }));
  };

  const initPage = () => { initDm(); initChat(); initHome(); };
  document.addEventListener('page:ready', initPage);
  initPage();

  const sx = document.getElementById('sx');
  if (sx) {
    const q = document.getElementById('sx-q');
    const list = document.getElementById('sx-list');
    let data = null;
    let loadedAt = 0;
    let results = [];
    let sel = 0;
    const norm = t => (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/æ/g, 'ae').replace(/ø/g, 'o').replace(/å/g, 'a');
    const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const mark = (t, terms) => {
      let out = esc(t);
      terms.forEach(term => {
        if (term.length < 2) return;
        const i = norm(t).indexOf(term);
        if (i < 0) return;
        const raw = t.slice(i, i + term.length);
        out = out.replace(esc(raw), '<mark>' + esc(raw) + '</mark>');
      });
      return out;
    };
    const load = async () => {
      if (data && Date.now() - loadedAt < 30000) return data;
      try {
        const r = await fetch('index.php?ajax=search', { credentials: 'same-origin' });
        data = await r.json();
        data.forEach(d => { d._t = norm(d.title); d._x = norm(d.text); });
        loadedAt = Date.now();
      } catch (e) { data = data || []; }
      return data;
    };
    const render = () => {
      const terms = norm(q.value).trim().split(/\s+/).filter(Boolean);
      if (!data) { list.innerHTML = '<li class="sx-empty">Henter…</li>'; return; }
      if (!terms.length) {
        results = data.filter(d => d.type === 'Side').concat(data.filter(d => d.type === 'Påmindelse').slice(0, 5));
      } else {
        results = data.map(d => {
          let score = 0;
          for (const t of terms) {
            if (d._t.startsWith(t)) score += 6;
            else if (d._t.includes(t)) score += 4;
            else if (d._x.includes(t)) score += 1;
            else return null;
          }
          if (d.type === 'Side') score += 1;
          return [score, d];
        }).filter(Boolean).sort((a, b) => b[0] - a[0]).slice(0, 12).map(x => x[1]);
      }
      sel = Math.min(sel, Math.max(0, results.length - 1));
      if (!results.length) {
        list.innerHTML = '<li class="sx-empty">Ingen resultater for "' + esc(q.value) + '"</li>';
        return;
      }
      list.innerHTML = results.map((d, i) => '<li role="option" data-i="' + i + '" class="' + (i === sel ? 'on' : '') + '"><span class="sx-type">' + esc(d.type) + '</span><span class="sx-t"><span class="sx-name">' + mark(d.title, terms) + '</span>' + (d.sub ? '<small>' + esc(d.sub) + '</small>' : '') + '</span></li>').join('');
    };
    const go = d => {
      if (!d) return;
      closeS();
      const url = new URL(d.url, location.href);
      const a = navLinks.find(l => new URL(l.href).search === url.search);
      if (a) setActive(a);
      swap(url.href);
    };
    const openS = async () => {
      sx.hidden = false;
      requestAnimationFrame(() => sx.classList.add('show'));
      q.value = '';
      sel = 0;
      render();
      q.focus();
      await load();
      render();
    };
    const closeS = () => {
      sx.classList.remove('show');
      setTimeout(() => { sx.hidden = true; }, 200);
    };
    q.addEventListener('input', () => { sel = 0; render(); });
    q.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(results.length - 1, sel + 1); render(); list.querySelector('.on')?.scrollIntoView({ block: 'nearest' }); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); render(); list.querySelector('.on')?.scrollIntoView({ block: 'nearest' }); }
      else if (e.key === 'Enter') { e.preventDefault(); go(results[sel]); }
      else if (e.key === 'Escape') { closeS(); }
    });
    list.addEventListener('mousemove', e => {
      const li = e.target.closest('li[data-i]');
      if (li && Number(li.dataset.i) !== sel) { sel = Number(li.dataset.i); list.querySelectorAll('li').forEach(x => x.classList.toggle('on', x === li)); }
    });
    list.addEventListener('click', e => {
      const li = e.target.closest('li[data-i]');
      if (li) go(results[Number(li.dataset.i)]);
    });
    sx.addEventListener('click', e => { if (e.target === sx) closeS(); });
    document.addEventListener('click', e => { if (e.target.closest('[data-search-open]')) openS(); });
    document.addEventListener('keydown', e => {
      const typing = /input|textarea|select/i.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); sx.hidden ? openS() : closeS(); }
      else if (e.key === '/' && !typing && sx.hidden) { e.preventDefault(); openS(); }
      else if (e.key === 'Escape' && !sx.hidden) closeS();
    });
    document.addEventListener('submit', () => { data = null; }, true);
  }

  const mobile = () => matchMedia('(max-width: 900px)').matches || matchMedia('(pointer: coarse)').matches;
  const mp = document.getElementById('mp');
  if (!mp || mobile()) return;
  let VIDEO = mp.dataset.v || '';
  let LIST = mp.dataset.list || '';
  const $ = id => document.getElementById(id);
  const seek = $('mp-seek');
  const vol = $('mp-volume');
  const title = $('mp-title');
  let player = null;
  let ready = false;
  let wantPlay = false;
  let seeking = false;
  let apiPromise = null;

  const marquee = (el, text) => {
    if (!el) return;
    let inner = el.querySelector(':scope > .mq');
    if (!inner || inner.textContent !== text) {
      el.textContent = '';
      inner = document.createElement('span');
      inner.className = 'mq';
      inner.textContent = text;
      el.appendChild(inner);
    }
    el.title = text;
    requestAnimationFrame(() => {
      const over = inner.scrollWidth - el.clientWidth;
      if (over > 4 && el.clientWidth > 0) {
        el.classList.add('mq-on');
        el.style.setProperty('--mq', -over + 'px');
        el.style.setProperty('--mq-t', Math.max(7, 4 + over / 22).toFixed(1) + 's');
      } else {
        el.classList.remove('mq-on');
      }
    });
  };

  const fmt = s => {
    s = Math.max(0, Math.floor(s || 0));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  };

  const clamp = (x, y) => {
    const w = mp.offsetWidth, h = mp.offsetHeight;
    return [Math.min(Math.max(8, x), innerWidth - w - 8), Math.min(Math.max(8, y), innerHeight - h - 8)];
  };

  const applyPos = () => {
    const pos = store.get('mp-pos', null);
    if (!pos) { mp.style.left = ''; mp.style.top = ''; mp.classList.remove('moved'); return; }
    const [x, y] = clamp(pos[0], pos[1]);
    mp.style.left = x + 'px';
    mp.style.top = y + 'px';
    mp.classList.add('moved');
  };

  const dockRect = () => {
    const d = document.querySelector('.mp-dock');
    if (!d) return null;
    const r = d.getBoundingClientRect();
    return r.width ? r : null;
  };

  const flyTransform = d => {
    const a = mp.getBoundingClientRect();
    const dx = d.left + d.width / 2 - (a.left + a.width / 2);
    const dy = d.top + d.height / 2 - (a.top + a.height / 2);
    return `translate(${dx}px, ${dy}px) scale(${Math.max(.12, d.width / a.width)})`;
  };

  let flyTimer;
  const open = (instant) => {
    clearTimeout(flyTimer);
    document.documentElement.classList.remove('mp-closed');
    mp.hidden = false;
    applyPos();
    store.set('mp-open', true);
    const d = !instant && !reduce && dockRect();
    if (d) {
      mp.style.transition = 'none';
      mp.style.transform = flyTransform(d);
      mp.style.opacity = '0';
      mp.classList.add('show');
      mp.getBoundingClientRect();
      mp.style.transition = 'transform .55s cubic-bezier(.2, .9, .2, 1), opacity .35s ease';
      mp.style.transform = '';
      mp.style.opacity = '';
      flyTimer = setTimeout(() => { mp.style.transition = ''; }, 600);
    } else {
      requestAnimationFrame(() => mp.classList.add('show'));
    }
    create();
  };

  const close = () => {
    clearTimeout(flyTimer);
    store.set('mp-open', false);
    document.documentElement.classList.add('mp-closed');
    const d = !reduce && dockRect();
    if (d) {
      mp.style.transition = 'transform .5s cubic-bezier(.5, 0, .2, 1), opacity .45s ease .05s';
      mp.style.transform = flyTransform(d);
      mp.style.opacity = '0';
      document.querySelector('.mp-dock').classList.add('land');
      flyTimer = setTimeout(() => {
        mp.hidden = true;
        mp.classList.remove('show');
        mp.style.transition = '';
        mp.style.transform = '';
        mp.style.opacity = '';
        const dk = document.querySelector('.mp-dock');
        dk && dk.classList.remove('land');
      }, 520);
    } else {
      mp.classList.remove('show');
      flyTimer = setTimeout(() => { mp.hidden = true; }, 300);
    }
  };

  const loadApi = () => apiPromise || (apiPromise = new Promise(resolve => {
    if (window.YT && window.YT.Player) return resolve();
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { prev && prev(); resolve(); };
    const s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(s);
  }));

  const updateTitle = () => {
    try {
      const d = player.getVideoData();
      if (d && d.title) {
        marquee(title, d.title + (d.author ? ' · ' + d.author : ''));
        store.set('mp-track', d.title);
        document.querySelectorAll('.mp-dock-t').forEach(t => marquee(t, d.title));
      }
    } catch (e) {}
  };

  const create = () => loadApi().then(() => new Promise(resolve => {
    if (player) return resolve();
    player = new YT.Player('mp-yt', {
      width: '100%',
      height: '100%',
      videoId: VIDEO || undefined,
      playerVars: Object.assign(LIST ? { listType: 'playlist', list: LIST } : {}, { autoplay: 0, playsinline: 1, rel: 0, modestbranding: 1, controls: 0, disablekb: 1 }),
      events: {
        onReady: () => {
          ready = true;
          player.setVolume(Number(vol.value));
          if (store.get('mp-muted', false)) player.mute();
          mp.classList.add('has-video');
          const saved = store.get('mp-state', null);
          if (saved && saved.key === VIDEO + '|' + LIST && saved.t > 2) {
            if (LIST) player.cuePlaylist({ list: LIST, listType: 'playlist', index: saved.index || 0, startSeconds: saved.t });
            else player.cueVideoById({ videoId: VIDEO, startSeconds: saved.t });
            if (saved.playing || wantPlay) setTimeout(() => player.playVideo(), 400);
          } else if (wantPlay) {
            player.playVideo();
          }
          resolve();
        },
        onStateChange: e => {
          mp.classList.toggle('playing', e.data === YT.PlayerState.PLAYING);
          document.documentElement.classList.toggle('mp-playing', e.data === YT.PlayerState.PLAYING);
          if (e.data === YT.PlayerState.PLAYING || e.data === YT.PlayerState.CUED) updateTitle();
        },
      },
    });
  }));

  const act = async what => {
    if (what === 'mute') {
      const muted = !mp.classList.contains('muted');
      mp.classList.toggle('muted', muted);
      document.documentElement.classList.toggle('mp-muted', muted);
      store.set('mp-muted', muted);
      if (player && ready) muted ? player.mute() : player.unMute();
      return;
    }
    if (!player || !ready) {
      if (what === 'toggle') wantPlay = true;
      create();
      return;
    }
    if (what === 'toggle') {
      player.getPlayerState() === YT.PlayerState.PLAYING ? player.pauseVideo() : player.playVideo();
    } else if (what === 'next') {
      player.nextVideo();
    } else if (what === 'prev') {
      player.getCurrentTime() > 4 ? player.seekTo(0, true) : player.previousVideo();
    }
  };

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-mp]');
    if (b) act(b.dataset.mp);
  });

  document.addEventListener('click', e => {
    if (e.target.closest('[data-player-open]')) {
      if (mobile()) return;
      e.preventDefault();
      mp.hidden ? open() : close();
    }
    if (e.target.closest('[data-player-close]')) close();
  });

  vol.value = store.get('mp-volume', 60);
  mp.classList.toggle('muted', store.get('mp-muted', false));
  document.documentElement.classList.toggle('mp-muted', store.get('mp-muted', false));
  const paintVol = () => {
    const v = String(store.get('mp-volume', 60));
    document.querySelectorAll('.mp-volume').forEach(r => {
      if (r.value !== v) r.value = v;
      r.style.setProperty('--v', v + '%');
    });
  };
  paintVol();
  document.addEventListener('input', e => {
    if (!e.target.classList || !e.target.classList.contains('mp-volume')) return;
    const v = Number(e.target.value);
    store.set('mp-volume', v);
    paintVol();
    if (player && ready) {
      player.setVolume(v);
      if (v > 0 && mp.classList.contains('muted')) {
        player.unMute();
        mp.classList.remove('muted');
        document.documentElement.classList.remove('mp-muted');
        store.set('mp-muted', false);
      }
    }
  });

  seek.addEventListener('input', () => { seeking = true; seek.style.setProperty('--v', seek.value / 10 + '%'); });
  seek.addEventListener('change', () => {
    seeking = false;
    if (player && ready) player.seekTo(player.getDuration() * seek.value / 1000, true);
  });

  const saveMusic = () => {
    if (!player || !ready || !player.getCurrentTime) return;
    try {
      store.set('mp-state', {
        key: VIDEO + '|' + LIST,
        index: LIST && player.getPlaylistIndex ? Math.max(0, player.getPlaylistIndex()) : 0,
        t: Math.floor(player.getCurrentTime() || 0),
        playing: player.getPlayerState() === YT.PlayerState.PLAYING,
      });
    } catch (e) {}
  };
  window.__saveMusic = saveMusic;
  setInterval(saveMusic, 3000);
  addEventListener('pagehide', saveMusic);
  document.addEventListener('visibilitychange', () => { if (document.hidden) saveMusic(); });

  setInterval(() => {
    if (!player || !ready || seeking) return;
    const d = player.getDuration() || 0, c = player.getCurrentTime() || 0;
    seek.value = d ? Math.round(c / d * 1000) : 0;
    seek.style.setProperty('--v', (d ? c / d * 100 : 0) + '%');
    $('mp-cur').textContent = fmt(c);
    $('mp-dur').textContent = fmt(d);
  }, 500);

  const head = mp.querySelector('[data-drag]');
  let drag = null;
  head.addEventListener('pointerdown', e => {
    if (e.target.closest('button')) return;
    const r = mp.getBoundingClientRect();
    drag = { dx: e.clientX - r.left, dy: e.clientY - r.top };
    head.setPointerCapture(e.pointerId);
    mp.classList.add('dragging', 'moved');
  });
  head.addEventListener('pointermove', e => {
    if (!drag) return;
    const [x, y] = clamp(e.clientX - drag.dx, e.clientY - drag.dy);
    mp.style.left = x + 'px';
    mp.style.top = y + 'px';
  });
  const end = () => {
    if (!drag) return;
    drag = null;
    mp.classList.remove('dragging');
    store.set('mp-pos', [parseFloat(mp.style.left), parseFloat(mp.style.top)]);
  };
  head.addEventListener('pointerup', end);
  head.addEventListener('pointercancel', end);
  head.addEventListener('dblclick', () => { store.set('mp-pos', null); applyPos(); });
  addEventListener('resize', () => { if (mp.classList.contains('moved')) applyPos(); });

  const urlForm = $('mp-url');
  const err = $('mp-err');
  const linkBtn = mp.querySelector('[data-mp-link]');
  linkBtn.addEventListener('click', () => {
    urlForm.hidden = !urlForm.hidden;
    linkBtn.setAttribute('aria-expanded', String(!urlForm.hidden));
    linkBtn.classList.toggle('on', !urlForm.hidden);
    if (!urlForm.hidden) urlForm.querySelector('input[name=url]').select();
    if (mp.classList.contains('moved')) applyPos();
  });
  urlForm.addEventListener('submit', async e => {
    e.preventDefault();
    e.stopPropagation();
    err.hidden = true;
    let data;
    try {
      const r = await fetch('index.php', { method: 'POST', body: new FormData(urlForm), credentials: 'same-origin' });
      data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Fejl');
    } catch (x) {
      err.textContent = x.message && x.message !== 'Failed to fetch' ? x.message : 'Kunne ikke gemme linket.';
      err.hidden = false;
      return;
    }
    VIDEO = data.v;
    LIST = data.list;
    marquee(title, 'Henter…');
    urlForm.hidden = true;
    linkBtn.classList.remove('on');
    linkBtn.setAttribute('aria-expanded', 'false');
    if (player && ready) {
      if (LIST) player.cuePlaylist({ list: LIST, listType: 'playlist', index: 0 });
      else player.cueVideoById(VIDEO);
      mp.classList.remove('playing');
      setTimeout(updateTitle, 1200);
    } else {
      create();
    }
  });

  const paintDock = () => {
    const t = store.get('mp-track', '');
    if (t) document.querySelectorAll('.mp-dock-t').forEach(el => marquee(el, t));
  };
  paintDock();
  new MutationObserver(() => { paintDock(); paintVol(); }).observe(document.body, { childList: true, subtree: false });
  document.addEventListener('click', () => setTimeout(() => { paintDock(); paintVol(); }, 400));

  if (store.get('mp-open', true)) open(true);
  else document.documentElement.classList.add('mp-closed');
})();

(() => {
  const ok = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const csrf = () => (document.querySelector('input[name="csrf"]') || {}).value || '';
  const post = (action, sub) => {
    const fd = new FormData();
    fd.append('csrf', csrf());
    fd.append('action', action);
    if (sub) fd.append('sub', JSON.stringify(sub));
    return fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).catch(() => ({}));
  };
  const keyBytes = b64 => {
    const s = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(s, c => c.charCodeAt(0));
  };
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  let reg = null;
  let vapid = '';

  const ready = ok ? navigator.serviceWorker.register('sw.js', { scope: './' }).then(r => { reg = r; return navigator.serviceWorker.ready; }).catch(() => null) : Promise.resolve(null);

  const current = async () => {
    await ready;
    return reg ? reg.pushManager.getSubscription() : null;
  };

  const subscribe = async () => {
    await ready;
    if (!reg) return null;
    if (!vapid) {
      const j = await fetch('index.php?ajax=push_key', { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({}));
      vapid = j.key || '';
    }
    if (!vapid) return null;
    let sub = await reg.pushManager.getSubscription();
    if (sub) {
      const k = sub.options && sub.options.applicationServerKey;
      const same = k && btoa(String.fromCharCode(...new Uint8Array(k))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') === vapid;
      if (!same) { await sub.unsubscribe(); sub = null; }
    }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapid) });
    await post('push_subscribe', sub.toJSON());
    return sub;
  };

  if (ok && Notification.permission === 'granted') subscribe().catch(() => {});

  const paint = async () => {
    const box = document.querySelector('[data-push]');
    if (!box) return;
    const status = box.querySelector('[data-push-status]');
    const on = box.querySelector('[data-push-on]');
    const test = box.querySelector('[data-push-test]');
    const off = box.querySelector('[data-push-off]');
    on.hidden = test.hidden = off.hidden = true;
    if (!ok) {
      status.textContent = ios && !standalone
        ? 'På iPhone skal dashboardet først føjes til hjemmeskærmen og åbnes derfra. Så kan du slå notifikationer til her.'
        : 'Denne browser understøtter ikke notifikationer.';
      return;
    }
    if (Notification.permission === 'denied') {
      status.textContent = 'Notifikationer er blokeret. Tillad dem i browserens eller telefonens indstillinger for siden.';
      return;
    }
    const sub = Notification.permission === 'granted' ? await current() : null;
    if (sub) {
      status.textContent = 'Slået til på denne enhed.';
      test.hidden = off.hidden = false;
    } else {
      status.textContent = 'Slået fra på denne enhed.';
      on.hidden = false;
    }
  };

  document.addEventListener('click', async e => {
    const t = e.target.closest('[data-push-on], [data-push-test], [data-push-off]');
    if (!t) return;
    const status = document.querySelector('[data-push-status]');
    t.disabled = true;
    try {
      if (t.matches('[data-push-on]')) {
        const p = await Notification.requestPermission();
        if (p === 'granted') await subscribe();
      } else if (t.matches('[data-push-test]')) {
        const j = await post('push_test');
        if (status) status.textContent = j.ok ? 'Test sendt – den dukker op om et øjeblik.' : 'Testen kunne ikke sendes. Prøv at slå notifikationer fra og til igen.';
        t.disabled = false;
        return;
      } else {
        const sub = await current();
        if (sub) { await post('push_unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
      }
    } catch (err) {
      if (status) status.textContent = 'Det lykkedes ikke: ' + (err && err.message ? err.message : 'ukendt fejl');
      t.disabled = false;
      return;
    }
    t.disabled = false;
    paint();
  });

  const clearBadge = () => {
    if (document.hidden || !('clearAppBadge' in navigator)) return;
    if (!document.querySelector('[data-unread-dot]:not([hidden])')) navigator.clearAppBadge().catch(() => {});
  };

  document.addEventListener('page:ready', () => { paint(); clearBadge(); });
  document.addEventListener('visibilitychange', clearBadge);
  paint();
  clearBadge();
})();

(() => {
  const boot = document.getElementById('boot');
  if (!boot) return;
  if (matchMedia('(max-width: 900px)').matches || matchMedia('(pointer: coarse)').matches) { boot.remove(); return; }
  const q = sel => boot.querySelector(sel);
  const inner = q('.boot-in');
  const text = q('[data-boot-text]');
  const bar = q('.boot-bar i');
  const tap = q('[data-boot-tap]');
  const logEl = q('[data-boot-log]');
  const hexEl = q('[data-boot-hex]');
  const name = boot.dataset.name || '';
  const fun = [
    'Varmer reaktoren op…', 'Kalibrerer sarkasme-modul…', 'Brygger kaffe til ' + name + '…', 'Tæller pixels… 1, 2, mange…',
    'Fjerner bugs med pincet…', 'Overtaler Wi-Fi\'en til at samarbejde…', 'Vækker hamstrene i serveren…', 'Polerer ikoner…',
    'Indlæser dårlige jokes… 42%', 'Tæller dine squats…', 'Sletter Internet Explorer… igen…', 'Downloader mere RAM…', 'Retter sidste bug (for real denne gang)…',
    'Spørger Siri om vej… nej, vent…', 'Stiller skærmen på dark mode…', 'Forhandler med firewall\'en…', 'Kompilerer gode vibes…'
  ];
  const sys = ['init kernel.sys', 'mount /dev/brain', 'load ui.dark', 'check coffee.level', 'sync reminders.db', 'handshake uplink', 'patch humor.dll', 'spin reactor.core', 'verify user.' + name.toLowerCase(), 'calibrate hud', 'decrypt vault', 'ping langkildelarsen.dk'];
  const rnd = (a, b) => Math.round(a + Math.random() * (b - a));
  const pad = n => String(n).padStart(2, '0');
  const pick = (arr, n) => arr.slice().sort(() => Math.random() - .5).slice(0, n);
  let audio = null;
  let timers = [];
  let loops = [];
  let running = false;
  const spark = [];

  const stopAll = () => { timers.forEach(clearTimeout); loops.forEach(clearInterval); timers = []; loops = []; };
  const say = (t, final) => { text.textContent = t; text.classList.toggle('final', !!final); };
  const tickClock = () => {
    const d = new Date();
    q('[data-boot-clock]').textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    q('[data-boot-date]').textContent = d.toLocaleDateString('da-DK', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
  };
  const logLine = t => {
    const div = document.createElement('div');
    div.innerHTML = '&gt; ' + t + ' ' + '.'.repeat(Math.max(2, 26 - t.length)) + ' <span class="ok">OK</span>';
    logEl.appendChild(div);
    while (logEl.children.length > 12) logEl.firstChild.remove();
  };
  const tickStats = () => {
    [['cpu', 20, 95], ['mem', 30, 80], ['net', 5, 100], ['cof', 60, 100]].forEach(([k, a, b]) => {
      const v = rnd(a, b);
      q('[data-stat="' + k + '"]').style.width = v + '%';
      q('[data-stat-v="' + k + '"]').textContent = v + '%';
    });
    spark.push(rnd(4, 28));
    if (spark.length > 30) spark.shift();
    q('[data-boot-spark]').setAttribute('points', spark.map((v, i) => (i * 100 / 29).toFixed(1) + ',' + (30 - v)).join(' '));
    const hx = Array.from({ length: 4 }, () => rnd(0, 65535).toString(16).toUpperCase().padStart(4, '0')).join(' ');
    const div = document.createElement('div');
    div.textContent = '0x' + rnd(4096, 65535).toString(16).toUpperCase() + '  ' + hx;
    hexEl.appendChild(div);
    while (hexEl.children.length > 6) hexEl.firstChild.remove();
    q('[data-boot-sec]').textContent = rnd(1, 9) + 'ABCDEFGH'[rnd(0, 7)];
    q('[data-boot-tmp]').textContent = (36 + Math.random()).toFixed(1) + '°';
  };

  const finish = () => {
    stopAll();
    if (audio && audio.paused) audio = null;
    boot.classList.add('out');
    try { sessionStorage.setItem('jv-booted', '1'); } catch (e) {}
    setTimeout(() => { boot.hidden = true; boot.classList.remove('out', 'on'); running = false; }, 950);
  };

  const hudOn = () => {
    tickClock();
    loops.push(setInterval(tickClock, 1000));
    loops.push(setInterval(tickStats, 450));
    let n = 0;
    loops.push(setInterval(() => { logLine(sys[n % sys.length]); n++; }, 380));
  };

  const sequence = () => {
    running = true;
    tap.hidden = true;
    inner.dataset.s = 'thinking';
    boot.classList.add('on');
    if (!loops.length) hudOn();
    const steps = pick(fun, 6);
    const per = 1000;
    steps.forEach((t, i) => timers.push(setTimeout(() => {
      say(t);
      const p = Math.round((i + 1) / steps.length * 100);
      bar.style.width = p + '%';
      q('[data-boot-pct]').textContent = String(p).padStart(3, '0') + '%';
      q('[data-boot-step]').textContent = (i + 1) + '/' + steps.length;
    }, 500 + i * per)));
    const end = 500 + steps.length * per;
    timers.push(setTimeout(() => { inner.dataset.s = 'speaking'; say('Velkommen hjem' + (name ? ', ' + name : '') + '.', true); logLine('all systems'); }, end));
    timers.push(setTimeout(finish, end + 2800));
  };

  const run = () => {
    if (running) return;
    stopAll();
    logEl.innerHTML = '';
    hexEl.innerHTML = '';
    bar.style.width = '0';
    q('[data-boot-pct]').textContent = '000%';
    q('[data-boot-step]').textContent = '0/6';
    inner.dataset.s = 'idle';
    say('');
    boot.hidden = false;
    boot.classList.remove('out', 'on');
    void boot.offsetWidth;
    if (!boot.dataset.sound) { sequence(); return; }
    if (audio) audio.pause();
    audio = new Audio(boot.dataset.sound);
    audio.volume = 0.9;
    sequence();
    audio.play().catch(() => {
      const unlock = () => {
        ['pointerdown', 'keydown', 'touchstart'].forEach(ev => document.removeEventListener(ev, unlock, true));
        if (running && audio && audio.paused) audio.play().catch(() => {});
      };
      ['pointerdown', 'keydown', 'touchstart'].forEach(ev => document.addEventListener(ev, unlock, true));
    });
  };

  boot.addEventListener('click', () => {
    if (running) finish();
  });

  document.addEventListener('click', e => {
    if (e.target.closest('[data-splash-run]')) { try { sessionStorage.removeItem('jv-booted'); } catch (x) {} run(); }
  });

  let seen = false;
  try { seen = sessionStorage.getItem('jv-booted') === '1'; } catch (e) {}
  if (!seen) run();
})();

(() => {
  const load = () => {
    const box = document.querySelector('[data-wx]');
    if (!box || box.dataset.busy) return;
    if (!box.hasAttribute('data-wx-refresh') && !box.querySelector('[data-wx-load]')) return;
    box.dataset.busy = '1';
    fetch('index.php?ajax=weather', { credentials: 'same-origin' })
      .then(r => r.ok ? r.text() : Promise.reject())
      .then(html => { box.innerHTML = html; box.removeAttribute('data-wx-refresh'); })
      .catch(() => {})
      .finally(() => { delete box.dataset.busy; });
  };
  document.addEventListener('page:ready', load);
  load();
})();

(() => {
  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  const parse = v => { const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || ''); return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) : null; };
  const days = ['søn', 'man', 'tir', 'ons', 'tor', 'fre', 'lør'];
  const months = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
  const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  const label = d => {
    const now = new Date();
    const t = pad(d.getHours()) + ':' + pad(d.getMinutes());
    const tm = new Date(now); tm.setDate(now.getDate() + 1);
    if (sameDay(d, now)) return 'I dag ' + t;
    if (sameDay(d, tm)) return 'I morgen ' + t;
    const diff = (d - now) / 86400000;
    if (diff > 0 && diff < 6) return days[d.getDay()].replace(/^./, c => c.toUpperCase()) + ' ' + t;
    return d.getDate() + '. ' + months[d.getMonth()].slice(0, 3) + ' ' + t;
  };
  const presets = () => {
    const now = new Date();
    const at = (addDays, h, m = 0) => { const d = new Date(now); d.setDate(d.getDate() + addDays); d.setHours(h, m, 0, 0); return d; };
    const inH = new Date(now.getTime() + 3600000); inH.setMinutes(Math.ceil(inH.getMinutes() / 15) * 15, 0, 0);
    const list = [['Om 1 time', inH]];
    if (now.getHours() < 18) list.push(['I aften', at(0, 19)]);
    list.push(['I morgen', at(1, 9)]);
    const toSat = (6 - now.getDay() + 7) % 7 || 7;
    list.push(['Weekend', at(toSat, 10)]);
    const toMon = (8 - now.getDay()) % 7 || 7;
    list.push(['Næste uge', at(toMon, 9)]);
    return list;
  };

  const init = () => {
    document.querySelectorAll('.rem-add').forEach(form => {
      const btn = form.querySelector('[data-when-btn]');
      const dp = form.querySelector('[data-dp]');
      const input = form.querySelector('[data-due]');
      if (!btn || !dp || btn.dataset.ready) return;
      btn.dataset.ready = '1';
      document.querySelectorAll('body > .dp').forEach(x => x.remove());
      document.body.appendChild(dp);
      const placeDp = () => {
        if (matchMedia('(max-width: 520px)').matches) { dp.style.left = ''; dp.style.top = ''; return; }
        const fr = form.getBoundingClientRect();
        const w = dp.offsetWidth, h = dp.offsetHeight;
        dp.style.left = Math.max(12, Math.min(innerWidth - w - 12, fr.right - w)) + 'px';
        dp.style.top = (fr.bottom + 10 + h > innerHeight - 12 && fr.top - h - 10 > 12 ? fr.top - h - 10 : fr.bottom + 10) + 'px';
      };
      let val = parse(input.value) || new Date(Date.now() + 3600000);
      let view = new Date(val.getFullYear(), val.getMonth(), 1);
      const lab = form.querySelector('[data-when-label]');
      const set = (d, presetName) => {
        val = new Date(d);
        input.value = iso(val);
        lab.textContent = presetName || label(val);
        render();
      };
      const render = () => {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const first = new Date(view);
        const lead = (first.getDay() + 6) % 7;
        const dim = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
        let cells = '';
        for (let i = 0; i < lead; i++) cells += '<span></span>';
        for (let d = 1; d <= dim; d++) {
          const dt = new Date(view.getFullYear(), view.getMonth(), d);
          const cls = [sameDay(dt, val) ? 'on' : '', sameDay(dt, today) ? 'today' : '', dt < today ? 'past' : ''].join(' ');
          cells += '<button type="button" data-d="' + d + '" class="' + cls + '"' + (dt < today ? ' disabled' : '') + '>' + d + '</button>';
        }
        const hours = [7, 8, 9, 10, 12, 14, 16, 17, 18, 19, 20, 21, 22];
        if (!hours.includes(val.getHours())) { hours.push(val.getHours()); hours.sort((a, b) => a - b); }
        dp.innerHTML =
          '<div class="dp-presets">' + presets().map(([n, d], i) => '<button type="button" data-p="' + i + '"><strong>' + n + '</strong><small>' + label(d) + '</small></button>').join('') + '</div>' +
          '<div class="dp-cal"><div class="dp-head"><button type="button" data-nav="-1" aria-label="Forrige måned">‹</button><span>' + months[view.getMonth()] + ' ' + view.getFullYear() + '</span><button type="button" data-nav="1" aria-label="Næste måned">›</button></div>' +
          '<div class="dp-dow"><span>M</span><span>T</span><span>O</span><span>T</span><span>F</span><span>L</span><span>S</span></div><div class="dp-grid">' + cells + '</div></div>' +
          '<div class="dp-time"><div class="dp-hours">' + hours.map(h => '<button type="button" data-h="' + h + '" class="' + (h === val.getHours() ? 'on' : '') + '">' + pad(h) + '</button>').join('') + '</div>' +
          '<div class="dp-mins">' + [0, 15, 30, 45].map(m => '<button type="button" data-m="' + m + '" class="' + (m === val.getMinutes() ? 'on' : '') + '">:' + pad(m) + '</button>').join('') + '</div></div>' +
          '<div class="dp-foot"><span>' + label(val) + '</span><button type="button" class="dp-ok" data-ok>Færdig</button></div>';
      };
      const open = () => { view = new Date(val.getFullYear(), val.getMonth(), 1); render(); dp.hidden = false; placeDp(); btn.setAttribute('aria-expanded', 'true'); };
      const close = () => { dp.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
      btn.addEventListener('click', e => { e.preventDefault(); dp.hidden ? open() : close(); });
      dp.addEventListener('click', e => {
        e.stopPropagation();
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.p !== undefined) { const [n, d] = presets()[+b.dataset.p]; set(d, n === 'Om 1 time' ? n : null); close(); return; }
        if (b.dataset.nav) { view.setMonth(view.getMonth() + +b.dataset.nav); render(); return; }
        if (b.dataset.d) { const d = new Date(val); d.setFullYear(view.getFullYear(), view.getMonth(), +b.dataset.d); set(d); return; }
        if (b.dataset.h) { const d = new Date(val); d.setHours(+b.dataset.h); set(d); return; }
        if (b.dataset.m) { const d = new Date(val); d.setMinutes(+b.dataset.m); set(d); return; }
        if (b.dataset.ok !== undefined) close();
      });
      document.addEventListener('click', e => { if (!dp.hidden && !form.contains(e.target) && !dp.contains(e.target)) close(); });
      addEventListener('resize', () => { if (!dp.hidden) placeDp(); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && !dp.hidden) close(); });
      form.addEventListener('submit', () => close());
    });
  };
  document.addEventListener('page:ready', init);
  document.addEventListener('cards:updated', init);
  init();
})();

(() => {
  const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const months = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
  const wd = ['Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag'];
  const nice = k => { const [y, m, d] = k.split('-').map(Number); const dt = new Date(y, m - 1, d); return wd[dt.getDay()] + ' ' + d + '. ' + months[m - 1]; };

  const init = () => {
    const card = document.querySelector('.c-cal');
    const grid = card && card.querySelector('[data-cal-days]');
    if (!grid || grid.dataset.ready) return;
    grid.dataset.ready = '1';
    let data = {};
    try { data = JSON.parse(grid.dataset.calDays || '{}') || {}; } catch (e) {}
    document.querySelectorAll('body > .cal-pop, body > .cal-tip').forEach(x => x.remove());
    const tip = card.querySelector('[data-cal-tip]');
    const pop = card.querySelector('[data-cal-pop]');
    document.body.append(tip, pop);
    const list = pop.querySelector('[data-cal-list]');
    const addForm = pop.querySelector('.cal-pop-add');
    const dateIn = pop.querySelector('[data-cal-date]');
    const timeIn = pop.querySelector('[data-cal-time]');
    const mins = pop.querySelector('[data-cal-mins]');
    let hour = '', minute = '00';
    const rows = (k, withX) => (data[k] || []).map(e => '<div class="cal-ev' + (e.l ? ' local' : '') + '"><span class="cal-ev-h">' + esc(e.h) + '</span><span class="cal-ev-t">' + esc(e.t) + (e.c && !e.l ? '<small>' + esc(e.c) + '</small>' : '') + '</span>' + (withX && e.l ? '<button name="id" value="' + esc(e.id) + '" class="cal-ev-x" aria-label="Slet">×</button>' : '') + '</div>').join('');
    const place = (el, btn) => {
      const br = btn.getBoundingClientRect();
      if (matchMedia('(max-width: 520px)').matches && el === pop) { el.style.left = ''; el.style.top = ''; return; }
      const w = el.offsetWidth, h = el.offsetHeight;
      el.style.left = Math.max(12, Math.min(innerWidth - w - 12, br.left + br.width / 2 - w / 2)) + 'px';
      const below = br.bottom + 8;
      el.style.top = (below + h > innerHeight - 12 && br.top - h - 8 > 12 ? br.top - h - 8 : below) + 'px';
    };
    addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });
    const syncTime = () => { timeIn.value = hour ? hour + ':' + minute : ''; mins.hidden = !hour; };

    grid.addEventListener('mouseover', e => {
      const b = e.target.closest('[data-day]');
      if (!b || !pop.hidden || !data[b.dataset.day] || matchMedia('(hover: none)').matches) { if (!b) tip.hidden = true; return; }
      tip.innerHTML = '<strong>' + esc(nice(b.dataset.day)) + '</strong>' + rows(b.dataset.day, false);
      tip.hidden = false;
      place(tip, b);
    });
    grid.addEventListener('mouseleave', () => { tip.hidden = true; });
    grid.addEventListener('click', e => {
      const b = e.target.closest('[data-day]');
      if (!b) return;
      tip.hidden = true;
      const k = b.dataset.day;
      grid.querySelectorAll('.day.sel').forEach(x => x.classList.remove('sel'));
      b.classList.add('sel');
      pop.querySelector('[data-cal-pop-title]').textContent = nice(k);
      list.querySelectorAll('.cal-ev, .cal-none').forEach(x => x.remove());
      list.insertAdjacentHTML('beforeend', rows(k, true) || '<p class="cal-none">Ingen aftaler denne dag.</p>');
      dateIn.value = k;
      hour = ''; minute = '00';
      pop.querySelectorAll('[data-th]').forEach(x => x.classList.toggle('on', x.dataset.th === ''));
      pop.querySelectorAll('[data-tm]').forEach(x => x.classList.toggle('on', x.dataset.tm === '00'));
      syncTime();
      pop.hidden = false;
      place(pop, b);
      addForm.querySelector('input[name=title]').focus();
    });
    pop.addEventListener('click', e => {
      const th = e.target.closest('[data-th]');
      if (th) { hour = th.dataset.th; pop.querySelectorAll('[data-th]').forEach(x => x.classList.toggle('on', x === th)); syncTime(); return; }
      const tm = e.target.closest('[data-tm]');
      if (tm) { minute = tm.dataset.tm; pop.querySelectorAll('[data-tm]').forEach(x => x.classList.toggle('on', x === tm)); syncTime(); return; }
      if (e.target.closest('[data-cal-close]')) { pop.hidden = true; grid.querySelectorAll('.day.sel').forEach(x => x.classList.remove('sel')); }
    });
    document.addEventListener('click', e => {
      if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('[data-day]')) { pop.hidden = true; grid.querySelectorAll('.day.sel').forEach(x => x.classList.remove('sel')); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { pop.hidden = true; tip.hidden = true; } });
  };
  document.addEventListener('page:ready', init);
  document.addEventListener('cards:updated', init);
  init();
})();

document.addEventListener('input', e => {
  const r = e.target.closest('input[data-live]');
  if (!r) return;
  const v = r.dataset.scale ? Number(r.value) / Number(r.dataset.scale) : r.value + (r.dataset.unit || '');
  document.body.style.setProperty(r.dataset.live, v);
  const out = document.querySelector('[data-out="' + r.name + '"]');
  if (out) out.textContent = r.value + (r.dataset.unit || '%');
});
