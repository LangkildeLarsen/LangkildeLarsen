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
  const setDrop = (k, url) => {
    const z = zone(k);
    if (!z) return;
    const prev = z.querySelector('[data-prev]');
    prev.style.backgroundImage = url ? 'url("' + url + '")' : '';
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
      setDrop('icon', b.dataset.icon || '');
      active = 'img';
      dlg.showModal();
      setTimeout(() => (b.dataset.edit ? f.url : f.name).focus(), 30);
    });
    dlg.querySelector('[data-lp-close]').addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
  }

  const grid = $('[data-lp-cards]');
  if (grid && document.body.classList.contains('editing')) {
    let drag = null;
    grid.addEventListener('dragstart', e => {
      drag = e.target.closest('.sc[data-id]');
      if (!drag) return;
      drag.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    grid.addEventListener('dragover', e => {
      if (!drag) return;
      e.preventDefault();
      const over = e.target.closest('.sc[data-id]');
      if (!over || over === drag) return;
      const r = over.getBoundingClientRect();
      const after = (e.clientX - r.left) / r.width > .5;
      over.parentNode.insertBefore(drag, after ? over.nextSibling : over);
    });
    grid.addEventListener('dragend', () => {
      if (!drag) return;
      drag.classList.remove('dragging');
      drag = null;
      const form = $('[data-lp-order]');
      form.order.value = [...grid.querySelectorAll('.sc[data-id]')].map(x => x.dataset.id).join(',');
      fetch('index.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).catch(() => {});
    });
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
