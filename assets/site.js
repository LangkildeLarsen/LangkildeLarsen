(() => {
  const root = document.documentElement;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(pointer: fine)').matches;

  const reveals = document.querySelectorAll('.reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach(el => el.classList.add('in'));
  } else {
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('in');
          io.unobserve(e.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach(el => io.observe(el));
  }

  const counters = document.querySelectorAll('[data-count]');
  const format = n => Math.round(n).toLocaleString('da-DK');
  const runCount = el => {
    const raw = el.dataset.count;
    const m = raw.match(/[\d.]+/);
    if (!m) return;
    const target = parseInt(m[0].replace(/\./g, ''), 10);
    if (!target) return;
    const isYear = target > 1900 && target < 2100 && !m[0].includes('.');
    const start = isYear ? target - 25 : 0;
    const t0 = performance.now();
    const dur = 1600;
    const step = now => {
      const p = Math.min(1, (now - t0) / dur);
      const eased = 1 - Math.pow(1 - p, 4);
      const v = start + (target - start) * eased;
      el.textContent = raw.replace(m[0], isYear ? String(Math.round(v)) : format(v));
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = raw;
    };
    requestAnimationFrame(step);
  };
  if (!reduce && 'IntersectionObserver' in window) {
    const co = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          runCount(e.target);
          co.unobserve(e.target);
        }
      });
    }, { threshold: 0.6 });
    counters.forEach(el => co.observe(el));
  }

  if (reduce || !fine) return;

  let mx = innerWidth / 2, my = innerHeight / 3, raf = 0;
  addEventListener('pointermove', e => {
    mx = e.clientX;
    my = e.clientY;
    if (!raf) raf = requestAnimationFrame(() => {
      root.style.setProperty('--mx', mx + 'px');
      root.style.setProperty('--my', my + 'px');
      raf = 0;
    });
  }, { passive: true });

  const tilt = document.querySelector('.tilt');
  if (tilt) {
    const box = tilt.parentElement;
    box.addEventListener('pointermove', e => {
      const r = box.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      tilt.style.setProperty('--rx', (-y * 10).toFixed(2) + 'deg');
      tilt.style.setProperty('--ry', (x * 12).toFixed(2) + 'deg');
      tilt.style.setProperty('--gx', ((x + 0.5) * 100).toFixed(1) + '%');
      tilt.style.setProperty('--gy', ((y + 0.5) * 100).toFixed(1) + '%');
      tilt.classList.add('active');
    });
    box.addEventListener('pointerleave', () => {
      tilt.classList.remove('active');
      tilt.style.setProperty('--rx', '0deg');
      tilt.style.setProperty('--ry', '0deg');
    });
  }

  document.querySelectorAll('.glass, .shot').forEach(el => {
    el.addEventListener('pointermove', e => {
      const r = el.getBoundingClientRect();
      el.style.setProperty('--x', (e.clientX - r.left) + 'px');
      el.style.setProperty('--y', (e.clientY - r.top) + 'px');
    });
  });

  document.querySelectorAll('.btn').forEach(btn => {
    btn.addEventListener('pointermove', e => {
      const r = btn.getBoundingClientRect();
      btn.style.setProperty('--tx', ((e.clientX - r.left - r.width / 2) * 0.18).toFixed(1) + 'px');
      btn.style.setProperty('--ty', ((e.clientY - r.top - r.height / 2) * 0.25).toFixed(1) + 'px');
    });
    btn.addEventListener('pointerleave', () => {
      btn.style.setProperty('--tx', '0px');
      btn.style.setProperty('--ty', '0px');
    });
  });

  const photo = document.querySelector('.hero-photo');
  if (photo) {
    addEventListener('scroll', () => {
      const y = Math.min(scrollY, 900);
      photo.style.setProperty('--py', (y * -0.08).toFixed(1) + 'px');
    }, { passive: true });
  }
})();

(() => {
  const seg = document.querySelector('.seg');
  const pill = seg && seg.querySelector('.seg-pill');
  if (!seg || !pill) return;
  const links = [...seg.querySelectorAll('a')];
  let current = null;

  const place = el => {
    current = el;
    links.forEach(a => a.classList.toggle('on', a === el));
    if (!el) {
      pill.style.opacity = '0';
      return;
    }
    pill.style.width = el.offsetWidth + 'px';
    pill.style.transform = `translateX(${el.offsetLeft}px)`;
    pill.style.opacity = '1';
  };

  const sections = links.map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  const spy = () => {
    const y = innerHeight * 0.35;
    let hit = null;
    sections.forEach((sec, i) => {
      const r = sec.getBoundingClientRect();
      if (r.top <= y && r.bottom > y) hit = links[i];
    });
    if (hit !== current) place(hit);
  };

  let lock = 0;
  links.forEach(a => a.addEventListener('click', () => {
    place(a);
    lock = Date.now() + 900;
  }));
  addEventListener('scroll', () => { if (Date.now() > lock) spy(); }, { passive: true });
  addEventListener('resize', () => place(current));
  spy();
})();
