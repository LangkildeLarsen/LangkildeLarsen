const LAUNCHPAD = 'https://langkildelarsen.dk/admin/?view=launch';

if (chrome.tabs && chrome.tabs.getCurrent) {
  chrome.tabs.getCurrent(tab => {
    if (!tab || tab.id === undefined) {
      location.replace(LAUNCHPAD);
      return;
    }
    chrome.tabs.create({ url: LAUNCHPAD, index: tab.index, active: true, windowId: tab.windowId }, () => {
      chrome.tabs.remove(tab.id);
    });
  });
} else {
  location.replace(LAUNCHPAD);
}
