(() => {
  const nav = document.querySelector('.rail-nav');
  const pill = nav && nav.querySelector('.rail-pill');
  if (!nav || !pill) return;
  const links = [...nav.querySelectorAll('a')];
  const active = nav.querySelector('a.active');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  const place = (el, animate) => {
    if (!el) {
      pill.style.opacity = '0';
      return;
    }
    pill.style.transition = animate && !reduce ? '' : 'none';
    pill.style.width = el.offsetWidth + 'px';
    pill.style.height = el.offsetHeight + 'px';
    pill.style.transform = `translate(${el.offsetLeft}px, ${el.offsetTop}px)`;
    pill.style.opacity = '1';
    if (!animate) pill.getBoundingClientRect();
  };

  let from = null;
  try { from = links[Number(sessionStorage.getItem('rail-from'))] || null; } catch (e) {}
  nav.classList.add('has-pill');
  if (from && active && from !== active) {
    place(from, false);
    requestAnimationFrame(() => requestAnimationFrame(() => place(active, true)));
  } else {
    place(active, false);
  }

  links.forEach((a, i) => {
    a.addEventListener('click', e => {
      if (a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey) return;
      try { sessionStorage.setItem('rail-from', String(links.indexOf(active))); } catch (err) {}
      if (a === active) return;
      e.preventDefault();
      active && active.classList.remove('active');
      a.classList.add('active');
      place(a, true);
      setTimeout(() => { location.href = a.href; }, reduce ? 0 : 260);
    });
  });

  let t;
  addEventListener('resize', () => {
    clearTimeout(t);
    t = setTimeout(() => place(nav.querySelector('a.active'), false), 80);
  });
})();
