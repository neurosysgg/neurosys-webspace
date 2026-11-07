/* Layout.php: the document around every page -- its head (the manifest's
 * stylesheet and module preloads), the header, <main id="content">, the
 * footer (the profiles, the contact line, the language switch) -- and
 * what every view shares: its title, its answer, the path it was asked
 * for, the 404 */
#include "views.h"

#include <stdio.h>
#include <string.h>

static const CSERVE_LANGUAGES LANGS = {{"en", "de"}, 2};
static const char *const ENDONYMS[] = {NS_ENDONYM_EN, NS_ENDONYM_DE};

/* the description: "neuro.SYS — electronic music." */
static const CSERVE_TR *description(CSERVE_ARENA *a) {
  return cserve_joined(
      a, " \xE2\x80\x94 ", cserve_verbatim(a, NS_NAME),
      cserve_joined(a, "", &T_LAYOUT_TAGLINE, cserve_verbatim(a, ".")));
}

static CSERVE_NODE *head(CSERVE_ARENA *a, const CSERVE_TR *title) {
  CSERVE_NODE *h =
      HAS(EL(head), ATTR(EL(meta), charset, NS_CHARSET),
          ATTR(ATTR(EL(meta), name, "viewport"), content, NS_VIEWPORT),
          HAS(EL(title), TR(title)),
          ATTR_TR(ATTR(EL(meta), name, "description"), content, description(a)),
          ATTR(ATTR(EL(link), rel, "stylesheet"), href, NS_STYLESHEET));
  CSERVE_LIST *preloads = cserve_list(a);
  for (int i = 0; i < ns_nmodules; i++) {
    cserve_list_add(preloads, ATTR(ATTR(EL(link), rel, "modulepreload"), href,
                                   ns_modules[i]));
  }
  return HAS(h, cserve_list_node(preloads));
}

CSERVE_NODE *ns_wordmark(CSERVE_ARENA *a) {
  const char *dot = strchr(NS_NAME, '.');
  return cserve_fragment(
      a, TEXT(cserve_arena_strndup(a, NS_NAME, (size_t)(dot - NS_NAME))),
      HAS(CLASS(EL(span), LOGO_DOT), TEXT(".")), TEXT(dot + 1));
}

static CSERVE_NODE *header(CSERVE_ARENA *a) {
  return HAS(CLASS(EL(header), SITE_HEADER),
             HAS(ATTR(CLASS(EL(a), LOGO), href, "/"), ns_wordmark(a)),
             HAS(CLASS(EL(nav), SITE_NAV),
                 HAS(ATTR(EL(a), href, "/releases"), TR(&T_LAYOUT_RELEASES))));
}

static CSERVE_NODE *profile(CSERVE_ARENA *a, const NS_PROFILE *p) {
  CSERVE_NODE *link =
      ATTR_TR(ATTR(CLASS(EL(a), PROFILE_LINK), href, p->url), title, p->label);
  link =
      ATTR(ATTR(link, target, "_blank"), rel, "noopener noreferrer external");
  return HAS(link, ATTR(ATTR_TR(ATTR(EL(img), src, p->icon), alt, p->label),
                        height, p->height));
}

/* the language line: the current one named, the others a link to the
 * switch, each in its own language */
static CSERVE_NODE *languages(CSERVE_ARENA *a, int current) {
  CSERVE_LIST *names = cserve_list(a);
  for (int i = 0; i < LANGS.n; i++) {
    if (i > 0) {
      cserve_list_add(names, TEXT(" \xC2\xB7 "));
    }
    CSERVE_NODE *name = TEXT(ENDONYMS[i]);
    if (i == current) {
      cserve_list_add(names, HAS(ATTR(EL(span), lang, LANGS.tags[i]), name));
    } else {
      CSERVE_NODE *to =
          ATTR(FLAG(EL(a), data_no_spa), href,
               cserve_arena_printf(a, "/language/%s", LANGS.tags[i]));
      cserve_list_add(names, HAS(ATTR(to, lang, LANGS.tags[i]), name));
    }
  }
  return HAS(EL(p), cserve_list_node(names));
}

static CSERVE_NODE *footer(CSERVE_ARENA *a, int lang) {
  CSERVE_NODE *f = CLASS(EL(footer), SITE_FOOTER);
  if (ns_nprofiles > 0) {
    CSERVE_LIST *links = cserve_list(a);
    for (int i = 0; i < ns_nprofiles; i++) {
      cserve_list_add(links, profile(a, &ns_profiles[i]));
    }
    f = HAS(f, HAS(ATTR_TR(CLASS(EL(nav), PROFILE_LINKS), aria_label,
                           &T_LAYOUT_PROFILES),
                   cserve_list_node(links)));
  }
  CSERVE_NODE *admin =
      ATTR_TR(ATTR(ATTR(CLASS(EL(a), ADMIN_LINK), href, NS_ADMIN_PATH), rel,
                   "nofollow"),
              aria_label, &T_LAYOUT_ADMIN);
  return HAS(f,
             HAS(EL(p), TEXT(NS_NAME " \xC2\xB7 "),
                 HAS(ATTR(EL(a), href, "mailto:" NS_EMAIL), TEXT(NS_EMAIL)),
                 TEXT(" \xC2\xB7 "),
                 HAS(ATTR(EL(a), href, "/imprint"), TR(&T_LAYOUT_IMPRINT)),
                 TEXT(" \xC2\xB7 "),
                 HAS(ATTR(EL(a), href, "/privacy"), TR(&T_LAYOUT_PRIVACY)),
                 TEXT(" \xC2\xB7 "), HAS(admin, TEXT("#"))),
             languages(a, lang));
}

static const CSERVE_NODE *shell(CSERVE_ARENA *a, const CSERVE_VIEW *v, int lang,
                                void *ud) {
  (void)ud;
  CSERVE_NODE *body = HAS(
      EL(body), header(a), HAS(ATTR(EL(main), id, "content"), v->content),
      footer(a, lang), ATTR(ATTR(EL(script), type, "module"), src, NS_SCRIPT));
  return cserve_document(
      a, HAS(ATTR(EL(html), lang, LANGS.tags[lang]), head(a, v->title), body));
}

const CSERVE_SITE ns_site = {&LANGS, "lang", shell, NULL};

const CSERVE_TR *ns_title(CSERVE_ARENA *a, const CSERVE_TR *section) {
  const CSERVE_TR *site = cserve_verbatim(a, NS_NAME);
  return section ? cserve_joined(a, " \xE2\x80\x94 ", section, site) : site;
}

void ns_page(const CSERVE_HTTPD_REQ *r, CSERVE_ARENA *a, const CSERVE_TR *title,
             const CSERVE_NODE *content, int status, CSERVE_HTTPD_REPLY *rep) {
  CSERVE_VIEW v = {title, content};
  cserve_page(&ns_site, r, a, &v, status, rep);
}

void ns_fail(CSERVE_HTTPD_REPLY *rep) {
  rep->headers[0] = '\0';
  cserve_httpd_text(rep, 500, "text/plain; charset=utf-8", "", 0);
}

const char *ns_path(CSERVE_ARENA *a, const CSERVE_HTTPD_REQ *r) {
  size_t n = strcspn(r->target, "?#");
  while (n > 0 && r->target[n - 1] == '/') {
    n--;
  }
  return n ? cserve_arena_strndup(a, r->target, n) : "/";
}

static int hex(int c) {
  return c >= '0' && c <= '9'   ? c - '0'
         : c >= 'a' && c <= 'f' ? c - 'a' + 10
         : c >= 'A' && c <= 'F' ? c - 'A' + 10
                                : -1;
}

/* s as rawurldecode() leaves it, in place: %XX a byte, a '%' without two
 * hex digits itself, '+' a '+' */
static void rawurldecode(char *s) {
  char *w = s;
  for (const char *p = s; *p; p++) {
    if (*p == '%' && hex(p[1]) >= 0 && hex(p[2]) >= 0) {
      *w++ = (char)(hex(p[1]) * 16 + hex(p[2]));
      p += 2;
    } else {
      *w++ = *p;
    }
  }
  *w = '\0';
}

int ns_match(CSERVE_ARENA *a, const CSERVE_HTTPD_REQ *r, const char *pattern,
             CSERVE_ROUTE_MATCH *m) {
  const char *path = ns_path(a, r);
  /* phpanta's routes are [^/]+ between single slashes: "//" fits none */
  if (strstr(path, "//") || !cserve_route_match(pattern, path, m)) {
    return 0;
  }
  /* each capture decoded as PlaceholderType::decode() does (they live in
   * m's store, which is the match's own) */
  for (int i = 0; i < m->n; i++) {
    rawurldecode((char *)m->p[i].value);
  }
  return 1;
}

void ns_not_found(const CSERVE_HTTPD_REQ *r, CSERVE_ARENA *a,
                  CSERVE_HTTPD_REPLY *rep) {
  NS_TERMINAL *t = ns_terminal(a, "error.log", 1, "find", ns_path(a, r));
  ns_terminal_field(t, &T_ERROR_ERROR, &T_ERROR_NOT_FOUND, NS_TONE_ERROR);
  CSERVE_NODE *content =
      HAS(CLASS(EL(section), PAGE_SECTION), ns_terminal_element(t),
          HAS(CLASS(EL(p), BACK_HOME),
              HAS(ATTR(EL(a), href, "/"), TR(&T_ERROR_HOME))));
  ns_page(r, a, ns_title(a, cserve_verbatim(a, "404")), content, 404, rep);
}

CSERVE_NODE *ns_accented(CSERVE_ARENA *a, const char *text) {
  size_t n = strlen(text);
  if (n == 0 || !strchr("!.?", text[n - 1])) {
    return TEXT(text);
  }
  return cserve_fragment(a, TEXT(cserve_arena_strndup(a, text, n - 1)),
                         HAS(CLASS(EL(span), BANG),
                             TEXT(cserve_arena_strndup(a, text + n - 1, 1))));
}
