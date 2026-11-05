const LAUNCHPAD = 'https://langkildelarsen.dk/admin/?view=launch';
if (chrome.tabs && chrome.tabs.getCurrent) {
  chrome.tabs.getCurrent(tab => {
    if (tab && tab.id !== undefined) chrome.tabs.update(tab.id, { url: LAUNCHPAD });
    else location.replace(LAUNCHPAD);
  });
} else {
  location.replace(LAUNCHPAD);
}
