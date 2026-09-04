(function () {
  const content = document.getElementById('content');

  // The fragment's <title> is HTML-escaped by ViewResponse, so it has to be decoded
  // before it reaches document.title — otherwise a track called "rock & roll" shows
  // up in the tab as "rock &amp; roll".
  function decodeEntities(text) {
    const el = document.createElement('textarea');
    el.innerHTML = text;
    return el.value;
  }

  function navigate(url) {
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) {
        if (!response.ok) { location.assign(url); return null; }
        return response.text();
      })
      .then(function (html) {
        if (html === null) return;
        const matches = html.match(/<title>([\s\S]*?)<\/title>/);
        if (matches) document.title = decodeEntities(matches[1]);
        else console.warn('No title found in HTML response');
        content.innerHTML = html.replace(/<title>[\s\S]*?<\/title>/, '');
        document.dispatchEvent(new Event('neurosys:navigate'));
        window.scrollTo(0, 0);
      })
      // pushState already ran, so the URL points at a page the visitor never got.
      // Hand the navigation back to the browser rather than strand them there.
      .catch(function () { location.assign(url); });
  }

  document.addEventListener('click', function (e) {
    // Let the browser handle open-in-new-tab/window and non-primary buttons.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    const link = e.target.closest('a[href^="/"]');
    if (!link || link.hasAttribute('data-no-spa')) return;
    e.preventDefault();
    history.pushState({}, '', link.href);
    navigate(link.href);
  });

  window.addEventListener('popstate', function () {
    navigate(location.pathname);
  });
}());
