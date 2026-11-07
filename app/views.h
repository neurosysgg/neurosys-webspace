#ifndef NS_VIEWS_H
#define NS_VIEWS_H

#include "arena.h"
#include "headers.h"
#include "html.h"
#include "markup.h"
#include "model.h"
#include "page.h"
#include "route.h"
#include "site.h"
#include "texts.h"
#include "vocab.h"

/* The neurosys app's pages (docs/research/c-serve.md, phase c4): each
 * view of src/NeuroSYS/View/ built as a tree, rendered by kit/ byte for
 * byte as Phpanta renders the PHP one -- test/cserve/diff.sh holds the two
 * side by side. */

/* the builders, short: EL(div), ATTR(n, href, "/"), HAS(n, kids...) */
#define EL(t) cserve_el(a, &cserve_tag_##t)
#define OWN(t) cserve_el(a, &ns_tag_##t)
#define ATTR(n, attr, v) cserve_attr(a, n, &cserve_attr_##attr, v)
#define ATTR_TR(n, attr, v) cserve_attr_tr(a, n, &cserve_attr_##attr, v)
#define FLAG(n, attr) cserve_attr_bool(a, n, &cserve_attr_##attr, 1)
#define CLASS(n, c) cserve_attr(a, n, &cserve_attr_class, NS_CLASS_##c)
#define HAS(n, ...) cserve_containing(a, n, __VA_ARGS__)
#define TEXT(s) cserve_text(a, s)
#define TR(t) cserve_tr(a, t)

/* the site: its languages (English first), the switch's cookie, the
 * shell (layout.c) */
extern const CSERVE_SITE ns_site;

/* View::title(): "section — neuro.SYS", or the name alone (NULL) */
const CSERVE_TR *ns_title(CSERVE_ARENA *a, const CSERVE_TR *section);

/* a view answered with status (a ViewResponse) */
void ns_page(const CSERVE_HTTPD_REQ *r, CSERVE_ARENA *a, const CSERVE_TR *title,
             const CSERVE_NODE *content, int status, CSERVE_HTTPD_REPLY *rep);

/* a 500 with nothing said: what a request gets when building its answer
 * failed (its arena, a header that didn't fit) */
void ns_fail(CSERVE_HTTPD_REPLY *rep);

/* the request's path as phpanta reads it (Request::path()): the target's,
 * undecoded, up to its '?' or '#', trailing '/'s off ("" is "/") -- into
 * the arena */
const char *ns_path(CSERVE_ARENA *a, const CSERVE_HTTPD_REQ *r);

/* a route's own match, on that path: the router matches the decoded one
 * (and lets "//" count for nothing), phpanta the path as sent -- its
 * literal segments undecoded, its captures rawurldecode()d after -- so a
 * handler asks again and reads its captures from here. 1 when it fits. */
int ns_match(CSERVE_ARENA *a, const CSERVE_HTTPD_REQ *r, const char *pattern,
             CSERVE_ROUTE_MATCH *m);

/* the 404 page for the request's path (NotFoundView) */
void ns_not_found(const CSERVE_HTTPD_REQ *r, CSERVE_ARENA *a,
                  CSERVE_HTTPD_REPLY *rep);

/* Accented: text, its last '!', '.' or '?' in a span.bang */
CSERVE_NODE *ns_accented(CSERVE_ARENA *a, const char *text);
/* the wordmark: neuro<span class="logo-dot">.</span>SYS */
CSERVE_NODE *ns_wordmark(CSERVE_ARENA *a);

/* the terminal (terminal.c): its rows, a field at a time */
typedef enum { NS_TONE_PLAIN, NS_TONE_OK, NS_TONE_ERROR } NS_TONE;
typedef struct NS_TERMINAL NS_TERMINAL;
/* label, the program and its arguments (NULL-terminated; the macro adds
 * it): an argument starting with '-' as it is, any other quoted */
NS_TERMINAL *ns_terminal_(CSERVE_ARENA *a, const char *label, int narrow,
                          const char *program, ...);
#define ns_terminal(a, label, narrow, ...)                                     \
  ns_terminal_(a, label, narrow, __VA_ARGS__, NULL)
void ns_terminal_field(NS_TERMINAL *t, const CSERVE_TR *key,
                       const CSERVE_TR *value, NS_TONE tone);
/* <terminal-window label command fields [narrow]> */
CSERVE_NODE *ns_terminal_element(NS_TERMINAL *t);

/* the pages */
void ns_home(const CSERVE_HTTPD_REQ *r, const char *body,
             const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_imprint(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_privacy(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_releases_page(const CSERVE_HTTPD_REQ *r, const char *body,
                      const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_release(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_download(const CSERVE_HTTPD_REQ *r, const char *body,
                 const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_language(const CSERVE_HTTPD_REQ *r, const char *body,
                 const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_demo(const CSERVE_HTTPD_REQ *r, const char *body,
             const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);
void ns_demo_audio(const CSERVE_HTTPD_REQ *r, const char *body,
                   const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep);

/* the privacy policy's halves (gen/privacy.c) */
const CSERVE_NODE *ns_privacy_en(CSERVE_ARENA *a);
const CSERVE_NODE *ns_privacy_de(CSERVE_ARENA *a);

/* the demos (demos.c): read from data/demos.json by init() */
int ns_demos_load(const char *data, char *err, size_t err_cap);
void ns_demos_free(void);

#endif
