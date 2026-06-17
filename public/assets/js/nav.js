(function () {
  const content = document.getElementById('content');

  function navigate(url) {
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) { return response.text(); })
      .then(function (html) {
        const matches = html.match(/<title>([\s\S]*?)<\/title>/);
        if (matches) document.title = matches[1];
        else console.warn('No title found in HTML response');
        content.innerHTML = html.replace(/<title>[\s\S]*?<\/title>/, '');
        window.scrollTo(0, 0);
      });
  }

  document.addEventListener('click', function (e) {
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
