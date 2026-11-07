/* HomeView: the wordmark, the tagline, a way to the releases, and the
 * SoundCloud profile's latest tracks */
#include "views.h"

void ns_home(const CSERVE_HTTPD_REQ *r, const char *body,
             const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  CSERVE_NODE *hero =
      HAS(CLASS(EL(section), HOME_HERO),
          HAS(CLASS(EL(p), HOME_EYEBROW), ns_wordmark(a)),
          HAS(CLASS(EL(h1), HOME_TITLE), TR(&T_LAYOUT_TAGLINE),
              HAS(CLASS(EL(span), BANG), TEXT("."))),
          HAS(ATTR(CLASS(EL(a), BTN_PRIMARY), href, "/releases"),
              TR(&T_LAYOUT_RELEASES), TEXT(" \xE2\x86\x92")));
  CSERVE_NODE *player =
      cserve_attr(a, OWN(soundcloud_profile), &ns_attr_player_player_style,
                  NS_PROFILE_STYLE);
  player = cserve_attr(a, player, &ns_attr_player_options, NS_PROFILE_OPTIONS);
  player = cserve_attr(a, player, &ns_attr_embed_height, NS_PROFILE_HEIGHT);
  CSERVE_NODE *tracks =
      HAS(CLASS(EL(section), PAGE_SECTION),
          HAS(CLASS(EL(h2), PAGE_HEADING), TR(&T_HOME_LATEST_TRACKS)), player);
  ns_page(r, a, ns_title(a, NULL), cserve_fragment(a, hero, tracks), 200, rep);
  cserve_arena_free(a);
}
