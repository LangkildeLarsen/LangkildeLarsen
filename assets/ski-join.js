(() => {
  const box = document.querySelector('[data-trip-start]');
  if (!box) return;
  const end = Number(box.dataset.tripStart) * 1000;
  const set = (sel, v) => { const el = box.querySelector(sel); if (el) el.textContent = v; };
  const tick = () => {
    const left = Math.max(0, end - Date.now());
    set('[data-c-d]', Math.floor(left / 864e5));
    set('[data-c-h]', Math.floor(left % 864e5 / 36e5));
    set('[data-c-m]', Math.floor(left % 36e5 / 6e4));
    set('[data-c-s]', String(Math.floor(left % 6e4 / 1e3)).padStart(2, '0'));
  };
  tick();
  setInterval(tick, 1000);
})();
