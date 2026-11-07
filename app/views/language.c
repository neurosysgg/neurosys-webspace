/* LanguageController: the language switch -- the cookie set, and back to
 * the page the visitor came from */
#include "views.h"

#include <string.h>
#include <strings.h>

/* LanguageController::back(): the Referer's path, when it stays on this
 * origin and isn't the switch itself, else home. The path is WHATWG's for
 * a URL of a special scheme -- everything after the host up to a '?' or
 * '#', "/" when there's nothing -- read here without its normalising:
 * what a browser sends as a Referer is a URL it has normalised already.
 * Any other URL goes home (WHATWG would give an opaque path, which no
 * browser sends). */
static const char *back(CSERVE_ARENA *a, const char *referer) {
  static const char *const SPECIAL[] = {"http://", "https://", "ftp://",
                                        "ws://",   "wss://",   "file://"};
  size_t skip = 0;
  for (size_t i = 0; referer && !skip && i < sizeof SPECIAL / sizeof *SPECIAL;
       i++) {
    size_t n = strlen(SPECIAL[i]);
    skip = strncasecmp(referer, SPECIAL[i], n) == 0 ? n : 0;
  }
  if (!skip) {
    return "/";
  }
  const char *p = referer + skip + strcspn(referer + skip, "/?#\\");
  size_t n = *p == '/' ? strcspn(p, "?#") : 0;
  const char *path = n ? cserve_arena_strndup(a, p, n) : "/";
  for (int i = 0; i < ns_site.languages->n; i++) {
    if (!strcmp(path, cserve_arena_printf(a, "/language/%s",
                                          ns_site.languages->tags[i]))) {
      return "/";
    }
  }
  return cserve_stays_on_origin(path) ? path : "/";
}

void ns_language(const CSERVE_HTTPD_REQ *r, const char *body,
                 const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  const char *tag = ns_match(a, r, "/language/{language}", &own)
                        ? cserve_route_param(&own, "language")
                        : NULL;
  if (!tag || cserve_language_of(ns_site.languages, tag) < 0) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  const char *cookie = cserve_arena_printf(
      a, "lang=%s; Path=/; Max-Age=31536000; SameSite=Lax; Secure; HttpOnly",
      tag);
  /* in RedirectResponse's order: its own headers, then where to */
  if (cserve_header(rep, CSERVE_H_SET_COOKIE, cookie) != 0 ||
      cserve_header(rep, CSERVE_H_CACHE_CONTROL,
                    cserve_cache_value(CSERVE_CACHE_NO_STORE)) != 0 ||
      cserve_header(rep, CSERVE_H_LOCATION,
                    back(a, cserve_httpd_header(r, "Referer"))) != 0) {
    ns_fail(rep);
  } else {
    cserve_httpd_text(rep, 303, NULL, "", 0);
  }
  cserve_arena_free(a);
}
