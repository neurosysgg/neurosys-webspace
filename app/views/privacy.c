/* PrivacyView: the privacy policy, both languages, the visitor's first --
 * each half the tree tools/app-data.php printed from data/privacy.*.html */
#include "views.h"

void ns_privacy(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/privacy", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  int german = cserve_page_language(&ns_site, r) == 1;
  CSERVE_NODE *de = HAS(ATTR(EL(section), lang, "de"), ns_privacy_de(a));
  CSERVE_NODE *en = HAS(ATTR(EL(section), lang, "en"), ns_privacy_en(a));
  CSERVE_NODE *content =
      HAS(CLASS(EL(section), PAGE_SECTION), german ? de : en, german ? en : de);
  const char *title = german ? "Datenschutzerkl\xC3\xA4rung" : "Privacy Policy";
  ns_page(r, a, ns_title(a, cserve_verbatim(a, title)), content, 200, rep);
  cserve_arena_free(a);
}
