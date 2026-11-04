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
  };

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
        results = data.filter(d => d.type === 'Side').concat(data.filter(d => d.type === 'Lifehack').slice(0, 5));
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
        title.textContent = d.title + (d.author ? ' · ' + d.author : '');
        store.set('mp-track', d.title);
        document.querySelectorAll('.mp-dock-t').forEach(t => { t.textContent = d.title; });
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
          if (wantPlay) player.playVideo();
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

  setInterval(() => {
    if (!player || !ready || seeking || mp.hidden) return;
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
    title.textContent = 'Henter…';
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
    if (t) document.querySelectorAll('.mp-dock-t').forEach(el => { el.textContent = t; });
  };
  paintDock();
  new MutationObserver(() => { paintDock(); paintVol(); }).observe(document.body, { childList: true, subtree: false });
  document.addEventListener('click', () => setTimeout(() => { paintDock(); paintVol(); }, 400));

  if (store.get('mp-open', true)) open(true);
  else document.documentElement.classList.add('mp-closed');
})();
