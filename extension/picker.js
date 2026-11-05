const sw = document.getElementById('sw');
const hexEl = document.getElementById('hex');
const msg = document.getElementById('msg');
const hist = document.getElementById('hist');

const copy = async hex => {
  try {
    await navigator.clipboard.writeText(hex);
  } catch (e) {
    const t = document.createElement('textarea');
    t.value = hex;
    document.body.appendChild(t);
    t.select();
    document.execCommand('copy');
    t.remove();
  }
  sw.style.background = hex;
  hexEl.textContent = hex.toUpperCase();
  msg.textContent = 'Kopieret til udklipsholderen';
  msg.classList.add('ok');
};

const renderHist = list => {
  hist.innerHTML = '';
  list.forEach(h => {
    const b = document.createElement('button');
    b.type = 'button';
    b.style.background = h;
    b.title = h.toUpperCase() + ' – klik for at kopiere';
    b.addEventListener('click', () => copy(h));
    hist.appendChild(b);
  });
};

const remember = hex => {
  chrome.storage.local.get({ colors: [] }, d => {
    const list = [hex, ...d.colors.filter(c => c !== hex)].slice(0, 16);
    chrome.storage.local.set({ colors: list });
    renderHist(list);
  });
};

chrome.storage.local.get({ colors: [] }, d => renderHist(d.colors));

const pick = async () => {
  if (!('EyeDropper' in window)) {
    msg.textContent = 'Din Chrome understøtter ikke farvevælgeren.';
    return;
  }
  try {
    const r = await new EyeDropper().open();
    const hex = r.sRGBHex.startsWith('#') ? r.sRGBHex : '#' + r.sRGBHex.match(/\d+/g).slice(0, 3).map(n => (+n).toString(16).padStart(2, '0')).join('');
    await copy(hex);
    remember(hex);
  } catch (e) {
    msg.textContent = 'Annulleret – klik for at prøve igen';
  }
};

document.getElementById('pick').addEventListener('click', pick);
pick();
