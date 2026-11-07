/* ImprintView: the legally required page, in both languages -- the
 * visitor's first. Its words are the law's and are written per language,
 * each half under its own lang, rather than as catalog entries. */
#include "views.h"

#include <stdarg.h>

static const char *const ADDRESS[] = {
    "Niclas Ahl",
    "c/o Adressgeber #2109",
    "An der alten Ziegelei 38",
    "48157 M\xC3\xBCnster",
    "Germany",
};

static CSERVE_NODE *address(CSERVE_ARENA *a) {
  CSERVE_LIST *lines = cserve_list(a);
  for (size_t i = 0; i < sizeof ADDRESS / sizeof *ADDRESS; i++) {
    if (i > 0) {
      cserve_list_add(lines, EL(br));
    }
    cserve_list_add(lines, TEXT(ADDRESS[i]));
  }
  return HAS(EL(p), cserve_list_node(lines));
}

static CSERVE_NODE *contact(CSERVE_ARENA *a) {
  return HAS(EL(p), TEXT("E-Mail: "),
             HAS(ATTR(EL(a), href, "mailto:" NS_EMAIL), TEXT(NS_EMAIL)));
}

/* one language's half: its h1, then each h2 and what it says */
static CSERVE_NODE *half(CSERVE_ARENA *a, const char *lang, const char *title,
                         const char *pursuant, const char *contact_heading,
                         const char *responsible) {
  return HAS(ATTR(EL(section), lang, lang), HAS(EL(h1), TEXT(title)),
             HAS(EL(h2), TEXT(pursuant)), address(a),
             HAS(EL(h2), TEXT(contact_heading)), contact(a),
             HAS(EL(h2), TEXT(responsible)), address(a));
}

void ns_imprint(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/imprint", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  int german = cserve_page_language(&ns_site, r) == 1;
  CSERVE_NODE *de =
      half(a, "de", "Impressum", "Angaben gem\xC3\xA4\xC3\x9F \xC2\xA7 5 DDG",
           "Kontakt", "Verantwortlicher im Sinne des \xC2\xA7 18 Abs. 2 MStV");
  CSERVE_NODE *en = half(
      a, "en", "Imprint", "Information pursuant to \xC2\xA7 5 DDG", "Contact",
      "Responsible for content pursuant to \xC2\xA7 18 Abs. 2 MStV");
  CSERVE_NODE *content =
      HAS(CLASS(EL(section), PAGE_SECTION), german ? de : en, german ? en : de);
  ns_page(r, a,
          ns_title(a, cserve_verbatim(a, german ? "Impressum" : "Imprint")),
          content, 200, rep);
  cserve_arena_free(a);
}
