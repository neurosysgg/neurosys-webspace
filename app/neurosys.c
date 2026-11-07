/* neurosys.gg as a c-serve app: its routes (RouteInitialization), the
 * security policy its pages need (Site::contentHosts()), what a method
 * no route takes is answered with (Router::refuse(), ::options()), and the
 * demos read before the first request. Every route not here yet is
 * still PHP's, behind the host's CGI front. */
#include "app.h"
#include "views.h"

#include <stdio.h>
#include <string.h>

static const CSERVE_ROUTE ROUTES[] = {
    {CSERVE_GET, "/", ns_home},
    {CSERVE_GET, "/releases", ns_releases_page},
    {CSERVE_GET, "/releases/{slug}", ns_release},
    {CSERVE_GET, "/releases/{slug}/{format}", ns_download},
    {CSERVE_GET, "/demos/{slug}", ns_demo},
    {CSERVE_GET, "/demos/{slug}/{label}", ns_demo_audio},
    {CSERVE_GET, "/imprint", ns_imprint},
    {CSERVE_GET, "/privacy", ns_privacy},
    {CSERVE_GET, "/language/{language}", ns_language},
};

static const char *const IMAGES[] = {NS_FILE_HOST, NULL};
static const char *const FRAMES[] = {NS_PLAYER_HOST, NULL};
static const CSERVE_SECURITY SECURITY = {.img = IMAGES, .frame = FRAMES};

/* a method no route takes: OPTIONS is told which they do (204), anything
 * else that the site is read-only, in the visitor's language (405) */
static void refuse(const CSERVE_HTTPD_REQ *r, const char *body, unsigned allow,
                   CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  char methods[64];
  if (!strcmp(r->method, "OPTIONS")) {
    cserve_route_allow_text(allow | CSERVE_OPTIONS, methods, sizeof methods);
    if (cserve_header(rep, CSERVE_H_ALLOW, methods) != 0) {
      ns_fail(rep);
      return;
    }
    cserve_httpd_text(rep, 204, NULL, "", 0);
    return;
  }
  cserve_route_allow_text(allow, methods, sizeof methods);
  CSERVE_ARENA *a = cserve_arena_new();
  const char *words = a ? cserve_tr_in(a, &T_FRAMEWORK_READ_ONLY,
                                       cserve_page_language(&ns_site, r))
                        : NULL;
  const char *text = words ? cserve_arena_printf(a, "%s\n", words) : NULL;
  if (!text ||
      cserve_header(rep, CSERVE_H_CACHE_CONTROL,
                    cserve_cache_value(CSERVE_CACHE_NO_STORE)) != 0 ||
      cserve_header(rep, CSERVE_H_ALLOW, methods) != 0) {
    ns_fail(rep);
  } else {
    cserve_httpd_text(rep, 405, "text/plain; charset=utf-8", text,
                      strlen(text));
  }
  cserve_arena_free(a);
}

static int init(const CSERVE_APP_ENV *env, char *err, size_t err_cap) {
  if (cserve_texts_complete(ns_texts, ns_ntexts, ns_site.languages->n, err,
                            err_cap) != 0) {
    return -1;
  }
  return ns_demos_load(env->data, err, err_cap);
}

static void fini(void) { ns_demos_free(); }

const CSERVE_APP cserve_app_neurosys = {
    CSERVE_APP_HEADER("neurosys"),
    .init = init,
    .routes = ROUTES,
    .nroutes = sizeof ROUTES / sizeof *ROUTES,
    .fini = fini,
    .security = &SECURITY,
    .refuse = refuse,
};
