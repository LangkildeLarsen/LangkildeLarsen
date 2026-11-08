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

  window.skiRefresh = refresh;
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
  const valid = ['alle', 'ski', 'lister', 'penge', 'rejsen'];
  const set = (tab, scroll) => {
    if (!valid.includes(tab)) tab = 'alle';
    document.body.dataset.tab = tab;
    document.querySelectorAll('[data-tab-go]').forEach(a => a.classList.toggle('active', a.dataset.tabGo === tab));
    if (scroll) scrollTo({ top: 0, behavior: 'instant' });
  };
  set('alle', false);
  document.addEventListener('click', e => {
    const a = e.target.closest('[data-tab-go]');
    if (!a) return;
    e.preventDefault();
    set(a.dataset.tabGo, true);
  });
})();

document.addEventListener('click', e => {
  const a = e.target.closest('[data-mp]');
  if (!a) return;
  e.preventDefault();
  const go = () => { location.href = 'mobilepay://'; };
  const amount = a.dataset.mp;
  if (navigator.clipboard) navigator.clipboard.writeText(amount).then(go, go);
  else go();
  a.textContent = amount + ' kr. kopieret';
  setTimeout(() => { a.textContent = 'Afregn'; }, 4000);
});

document.addEventListener('click', e => {
  const b = e.target.closest('[data-share]');
  if (!b) return;
  const url = b.dataset.share;
  const text = b.dataset.shareText || '';
  if (navigator.share) {
    navigator.share({ title: 'Skiferie Mayrhofen', text, url }).catch(() => {});
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(text + ' ' + url).then(() => { const t = b.textContent; b.textContent = 'Kopieret'; setTimeout(() => { b.textContent = t; }, 1600); });
  } else {
    prompt('Kopiér linket', url);
  }
});

document.addEventListener('change', e => {
  const input = e.target.closest('form[data-autosubmit] input[type="file"]');
  if (!input || !input.files.length) return;
  input.form.requestSubmit();
});
