(() => {
  const $ = s => document.querySelector(s);
  const pad = n => String(n).padStart(2, '0');

  const tick = () => {
    const d = new Date();
    const c = $('[data-lp-clock]');
    if (c) c.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
    const dt = $('[data-lp-date]');
    if (dt) dt.textContent = d.toLocaleDateString('da-DK', { weekday: 'long', day: 'numeric', month: 'long' }).toUpperCase();
  };
  tick();
  setInterval(tick, 1000);

  const search = $('[data-lp-search]');
  if (search) {
    const q = search.querySelector('input');
    setTimeout(() => q.focus(), 50);
    search.addEventListener('submit', e => {
      const v = q.value.trim();
      if (!v) { e.preventDefault(); return; }
      if (/^https?:\/\/\S+$/i.test(v)) { e.preventDefault(); location.href = v; return; }
      if (!/\s/.test(v) && /^[\w-]+(\.[\w-]+)+(:\d+)?(\/\S*)?$/.test(v)) { e.preventDefault(); location.href = 'https://' + v; }
    });
  }

  document.querySelectorAll('img[data-fallback]').forEach(img => {
    const swap = () => {
      const name = img.closest('.sc')?.querySelector('strong')?.textContent || '?';
      const s = document.createElement('span');
      s.className = img.className + ' mono';
      s.textContent = name.trim().charAt(0).toUpperCase();
      img.replaceWith(s);
    };
    if (img.complete && img.naturalWidth === 0) swap();
    else img.addEventListener('error', swap);
  });

  const dlg = $('[data-lp-dialog]');
  let active = 'img';
  const zone = k => dlg && dlg.querySelector('[data-drop="' + k + '"]');
  const posBox = dlg && dlg.querySelector('[data-pos-box]');
  const setPos = v => {
    if (!posBox) return;
    posBox.querySelector('[data-pos-in]').value = v;
    posBox.querySelector('[data-pos-img]').style.objectPosition = v;
    const [x, y] = v.split(' ');
    const dot = posBox.querySelector('[data-pos-dot]');
    dot.style.left = x;
    dot.style.top = y;
    const z = zone('img');
    if (z) z.querySelector('[data-prev]').style.backgroundPosition = v;
  };
  if (posBox) {
    const frame = posBox.querySelector('[data-pos-frame]');
    let down = false;
    const at = e => {
      const r = frame.getBoundingClientRect();
      const x = Math.round(Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100)));
      const y = Math.round(Math.min(100, Math.max(0, (e.clientY - r.top) / r.height * 100)));
      setPos(x + '% ' + y + '%');
    };
    frame.addEventListener('pointerdown', e => { down = true; frame.setPointerCapture(e.pointerId); at(e); });
    frame.addEventListener('pointermove', e => { if (down) at(e); });
    frame.addEventListener('pointerup', () => { down = false; });
  }
  const setOv = v => {
    if (!posBox) return;
    const r = posBox.querySelector('[data-ov-in]');
    r.value = v;
    posBox.querySelector('[data-ov-out]').textContent = v + '%';
    posBox.querySelector('[data-pos-frame]').style.setProperty('--ov', v / 100);
  };
  if (posBox) posBox.querySelector('[data-ov-in]').addEventListener('input', e => setOv(e.target.value));
  const setDrop = (k, url) => {
    const z = zone(k);
    if (!z) return;
    const prev = z.querySelector('[data-prev]');
    prev.style.backgroundImage = url ? 'url("' + url + '")' : '';
    if (k === 'img' && posBox) {
      posBox.hidden = !url;
      if (url) posBox.querySelector('[data-pos-img]').src = url;
    }
    z.classList.toggle('has', !!url);
    z.querySelector('input[type=checkbox]').checked = false;
  };
  const useFile = (k, file) => {
    if (!file || !/^image\//.test(file.type)) return;
    const z = zone(k);
    const dt = new DataTransfer();
    dt.items.add(file);
    z.querySelector('input[type=file]').files = dt.files;
    setDrop(k, URL.createObjectURL(file));
  };
  if (dlg) {
    const f = dlg.querySelector('form');
    dlg.querySelectorAll('[data-drop]').forEach(z => {
      const k = z.dataset.drop;
      const input = z.querySelector('input[type=file]');
      z.addEventListener('click', e => {
        if (e.target.closest('[data-clear]')) {
          e.preventDefault();
          input.value = '';
          setDrop(k, '');
          z.querySelector('input[type=checkbox]').checked = true;
          return;
        }
        active = k;
        input.click();
      });
      z.addEventListener('focus', () => { active = k; });
      z.addEventListener('mouseenter', () => { active = k; });
      z.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
      input.addEventListener('change', () => { if (input.files[0]) useFile(k, input.files[0]); });
      z.addEventListener('dragover', e => { e.preventDefault(); z.classList.add('over'); });
      z.addEventListener('dragleave', () => z.classList.remove('over'));
      z.addEventListener('drop', e => { e.preventDefault(); z.classList.remove('over'); useFile(k, e.dataTransfer.files[0]); });
    });
    dlg.addEventListener('paste', e => {
      const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
      if (!item) return;
      e.preventDefault();
      useFile(active, item.getAsFile());
    });
    document.addEventListener('click', e => {
      const b = e.target.closest('[data-edit]');
      if (!b) return;
      e.preventDefault();
      f.reset();
      f.id.value = b.dataset.edit || '';
      f.name.value = b.dataset.name || '';
      f.url.value = b.dataset.url || '';
      dlg.querySelector('[data-lp-dtitle]').textContent = b.dataset.edit ? 'Rediger genvej' : 'Ny genvej';
      setDrop('img', b.dataset.img || '');
      setPos(b.dataset.pos || '50% 50%');
      setOv(b.dataset.ov || '45');
      f.querySelector('[data-appicon]').checked = !!b.dataset.iconbg;
      f.querySelector('[data-iconbg]').value = b.dataset.iconbg || '#ffffff';
      setDrop('icon', b.dataset.icon || '');
      active = 'img';
      dlg.showModal();
      setTimeout(() => (b.dataset.edit ? f.url : f.name).focus(), 30);
    });
    dlg.querySelector('[data-lp-close]').addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
  }

  const grid = $('[data-lp-cards]');
  if (grid) {
    const editing = document.body.classList.contains('editing');
    grid.querySelectorAll('a, img').forEach(el => el.setAttribute('draggable', 'false'));
    let card = null, timer = null, dragging = false, suppress = false;
    let sx = 0, sy = 0, offX = 0, offY = 0, tx = 0, ty = 0;
    const save = () => {
      const form = $('[data-lp-order]');
      form.order.value = [...grid.querySelectorAll('.sc[data-id]')].map(x => x.dataset.id).join(',');
      fetch('index.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).catch(() => {});
    };
    const follow = (x, y) => {
      const r = card.getBoundingClientRect();
      const nx = r.left - tx, ny = r.top - ty;
      tx = x - offX - nx;
      ty = y - offY - ny;
      card.style.transform = 'translate(' + tx + 'px, ' + ty + 'px) scale(1.04)';
    };
    const start = e => {
      dragging = true;
      const r = card.getBoundingClientRect();
      offX = sx - r.left;
      offY = sy - r.top;
      tx = 0; ty = 0;
      card.classList.add('lift');
      grid.classList.add('sorting');
      try { card.setPointerCapture(e.pointerId); } catch (x) {}
      if (navigator.vibrate) navigator.vibrate(10);
    };
    const end = () => {
      clearTimeout(timer);
      if (dragging) {
        card.classList.remove('lift');
        card.style.transform = '';
        grid.classList.remove('sorting');
        suppress = true;
        setTimeout(() => { suppress = false; }, 250);
        save();
      }
      dragging = false;
      card = null;
    };
    grid.addEventListener('pointerdown', e => {
      const c = e.target.closest('.sc[data-id]');
      if (!c || e.button !== 0 || e.target.closest('.sc-tools')) return;
      card = c;
      sx = e.clientX; sy = e.clientY;
      e.preventDefault();
    });
    grid.addEventListener('pointermove', e => {
      if (!card) return;
      if (!dragging) {
        if (Math.hypot(e.clientX - sx, e.clientY - sy) < 6) return;
        start(e);
      }
      e.preventDefault();
      const prev = card.style.pointerEvents;
      card.style.pointerEvents = 'none';
      const over = document.elementFromPoint(e.clientX, e.clientY)?.closest('.sc[data-id]');
      card.style.pointerEvents = prev;
      if (over && over !== card) {
        const r = over.getBoundingClientRect();
        const after = (e.clientY - r.top) / r.height > .5 && Math.abs(e.clientY - (r.top + r.height / 2)) > r.height / 4 ? e.clientY > r.top + r.height / 2 : (e.clientX - r.left) / r.width > .5;
        grid.insertBefore(card, after ? over.nextSibling : over);
      }
      follow(e.clientX, e.clientY);
    });
    grid.addEventListener('pointerup', end);
    grid.addEventListener('pointercancel', end);
    grid.addEventListener('click', e => { if (suppress) { e.preventDefault(); e.stopPropagation(); } }, true);
    grid.addEventListener('contextmenu', e => { if (dragging) e.preventDefault(); });
  }

  const ask = $('[data-lp-ask]');
  if (ask) {
    const jv = $('[data-jv]');
    const say = $('[data-lp-say]');
    const input = ask.querySelector('input[name=text]');
    ask.addEventListener('submit', async e => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      input.placeholder = text;
      say.classList.remove('err');
      say.textContent = 'Tænker…';
      if (jv) jv.dataset.s = 'thinking';
      const fd = new FormData(ask);
      fd.set('text', text);
      let j;
      try {
        const r = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'json' } });
        j = await r.json();
      } catch (x) {
        j = { error: 'Kunne ikke få forbindelse. Prøv igen.' };
      }
      if (jv) jv.dataset.s = 'idle';
      input.placeholder = 'Spørg Jarvis…';
      say.textContent = String(j.error || j.reply || '').replace(/\*\*/g, '');
      say.classList.toggle('err', !!j.error);
    });
  }

  const wx = $('[data-wx-refresh]');
  if (wx) fetch('index.php?ajax=weather', { credentials: 'same-origin' }).catch(() => {});
})();
document.addEventListener('dragstart', e => { if (e.target.closest && e.target.closest('.sc')) e.preventDefault(); });
