/* ReleasesView, ReleaseView and DownloadController: the list, one
 * release's page (its terminal, cover, player, arrangement, downloads),
 * and a download -- a 303 to where the file is, or a 503 for one that
 * isn't uploaded yet */
#include "views.h"

#include <stdio.h>
#include <string.h>

static const NS_RELEASE *find(const char *slug) {
  for (int i = 0; i < ns_nreleases; i++) {
    if (!strcmp(ns_releases[i].slug, slug)) {
      return &ns_releases[i];
    }
  }
  return NULL;
}

static CSERVE_NODE *card(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  const char *sep = " \xC2\xB7 ";
  CSERVE_NODE *meta =
      HAS(OWN(release_meta),
          TR(cserve_phrase(a, &T_RELEASE_BEATS, "bpm",
                           cserve_arena_printf(a, "%d", rel->bpm))),
          TEXT(sep), TR(rel->key), TEXT(sep), TEXT(rel->genre), TEXT(sep),
          TR(rel->description));
  CSERVE_NODE *link =
      ATTR(EL(a), href, cserve_arena_printf(a, "/releases/%s", rel->slug));
  return HAS(cserve_attr(a, OWN(release_card), &ns_attr_card_slug, rel->slug),
             HAS(link, HAS(OWN(release_title), TEXT(rel->title)), meta));
}

void ns_releases_page(const CSERVE_HTTPD_REQ *r, const char *body,
                      const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/releases", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  CSERVE_LIST *cards = cserve_list(a);
  for (int i = 0; i < ns_nreleases; i++) {
    cserve_list_add(cards, card(a, &ns_releases[i]));
  }
  CSERVE_NODE *content =
      HAS(CLASS(EL(section), PAGE_SECTION),
          HAS(CLASS(EL(h2), PAGE_HEADING), TR(&T_LAYOUT_RELEASES)),
          HAS(OWN(release_list), cserve_list_node(cards)));
  ns_page(r, a, ns_title(a, &T_LAYOUT_RELEASES), content, 200, rep);
  cserve_arena_free(a);
}

static CSERVE_NODE *hero(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  NS_TERMINAL *t =
      ns_terminal(a, "release.log", 0, "./release", "--track", rel->title);
  ns_terminal_field(t, &T_TERMINAL_ARTIST, cserve_verbatim(a, NS_NAME),
                    NS_TONE_PLAIN);
  ns_terminal_field(t, &T_RELEASE_BPM,
                    cserve_verbatim(a, cserve_arena_printf(a, "%d", rel->bpm)),
                    NS_TONE_PLAIN);
  ns_terminal_field(t, &T_RELEASE_KEY, rel->key, NS_TONE_PLAIN);
  ns_terminal_field(t, &T_RELEASE_GENRE, cserve_verbatim(a, rel->genre),
                    NS_TONE_PLAIN);
  if (rel->time) {
    ns_terminal_field(t, &T_RELEASE_TIME, cserve_verbatim(a, rel->time),
                      NS_TONE_PLAIN);
  }
  if (rel->made_with) {
    ns_terminal_field(t, &T_RELEASE_MADE_WITH,
                      cserve_verbatim(a, rel->made_with), NS_TONE_PLAIN);
  }
  ns_terminal_field(t, &T_TERMINAL_STATUS, &T_RELEASE_READY, NS_TONE_OK);
  CSERVE_NODE *cover =
      cserve_attr(a, OWN(cover_art), &ns_attr_cover_art_src, rel->cover);
  cover =
      cserve_attr(a, cover, &ns_attr_cover_art_fallback, NS_COVER_PLACEHOLDER);
  cover = cserve_attr_tr(
      a, cover, &ns_attr_cover_art_alt,
      cserve_phrase(a, &T_RELEASE_COVER_ART, "title", rel->title));
  return HAS(CLASS(EL(section), HERO), ns_terminal_element(t), cover);
}

static CSERVE_NODE *player(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  const NS_EMBED *e = rel->embed;
  CSERVE_NODE *p = cserve_attr(a, OWN(soundcloud_player),
                               &ns_attr_player_track_id, e->track_id);
  p = cserve_attr(a, p, &ns_attr_player_permalink, e->permalink);
  if (e->secret) {
    p = cserve_attr(a, p, &ns_attr_player_secret_token, e->secret);
  }
  p = cserve_attr(a, p, &ns_attr_player_player_style, e->style);
  p = cserve_attr(a, p, &ns_attr_player_options, e->options);
  p = cserve_attr(a, p, &ns_attr_player_track_title, rel->title);
  return cserve_attr(a, p, &ns_attr_embed_height, e->height);
}

static CSERVE_NODE *arrangement(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  CSERVE_LIST *sections = cserve_list(a);
  for (int i = 0; i < rel->nsections; i++) {
    const NS_SECTION *s = &rel->sections[i];
    CSERVE_NODE *el = OWN(arrangement_section);
    if (s->kind) {
      el = cserve_attr(a, el, &ns_attr_arrangement_kind, s->kind);
    }
    cserve_list_add(sections,
                    HAS(el, HAS(CLASS(EL(span), SECTION_TIME), TEXT(s->time)),
                        HAS(CLASS(EL(span), SECTION_LABEL), TEXT(s->label))));
  }
  return HAS(OWN(release_arrangement), HAS(EL(h2), TR(&T_RELEASE_ARRANGEMENT)),
             cserve_list_node(sections));
}

static const CSERVE_TR *meta(CSERVE_ARENA *a, NS_META m) {
  switch (m) {
  case NS_META_STEMS:
    return cserve_phrase(a, &T_RELEASE_STEMS, "email", NS_EMAIL);
  case NS_META_MP3:
    return &T_RELEASE_MP3;
  case NS_META_OGG:
    return &T_RELEASE_OGG;
  case NS_META_LOSSY:
    return &T_RELEASE_LOSSY;
  default:
    return &T_RELEASE_LOSSLESS;
  }
}

static CSERVE_NODE *downloads(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  CSERVE_LIST *cards = cserve_list(a);
  for (int i = 0; i < rel->nformats; i++) {
    const NS_FORMAT *f = &rel->formats[i];
    CSERVE_NODE *link =
        ATTR(FLAG(EL(a), data_no_spa), href,
             cserve_arena_printf(a, "/releases/%s/%s", rel->slug, f->type));
    cserve_list_add(
        cards,
        HAS(cserve_attr(a, OWN(download_card), &ns_attr_card_format, f->type),
            HAS(link, HAS(OWN(download_label), TEXT(f->label)),
                HAS(OWN(download_meta), TR(meta(a, f->meta))))));
  }
  return HAS(OWN(download_list), HAS(EL(h2), TR(&T_RELEASE_DOWNLOADS)),
             cserve_list_node(cards));
}

static CSERVE_NODE *info(CSERVE_ARENA *a, const NS_RELEASE *rel) {
  CSERVE_NODE *s = HAS(
      CLASS(EL(section), RELEASE_INFO), HAS(EL(h1), ns_accented(a, rel->title)),
      HAS(CLASS(EL(p), TAGLINE), TEXT(NS_NAME " \xE2\x80\x94 "),
          TR(rel->description)));
  if (rel->embed) {
    s = HAS(s, player(a, rel));
  }
  if (rel->nsections > 0) {
    s = HAS(s, arrangement(a, rel));
  }
  return HAS(s, downloads(a, rel));
}

void ns_release(const CSERVE_HTTPD_REQ *r, const char *body,
                const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  const NS_RELEASE *rel = ns_match(a, r, "/releases/{slug}", &own)
                              ? find(cserve_route_param(&own, "slug"))
                              : NULL;
  if (!rel) {
    ns_not_found(r, a, rep);
  } else {
    ns_page(r, a, ns_title(a, cserve_verbatim(a, rel->title)),
            cserve_fragment(a, hero(a, rel), info(a, rel)), 200, rep);
  }
  cserve_arena_free(a);
}

void ns_download(const CSERVE_HTTPD_REQ *r, const char *body,
                 const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  const NS_RELEASE *rel = ns_match(a, r, "/releases/{slug}/{format}", &own)
                              ? find(cserve_route_param(&own, "slug"))
                              : NULL;
  const NS_FORMAT *f = NULL;
  for (int i = 0; rel && i < rel->nformats; i++) {
    if (!strcmp(rel->formats[i].type, cserve_route_param(&own, "format"))) {
      f = &rel->formats[i];
    }
  }
  if (!f) {
    ns_not_found(r, a, rep);
  } else if (!f->url) {
    /* not uploaded yet: the words alone, in the visitor's language */
    const char *words = cserve_tr_in(a, &T_ERROR_NOT_YET_AVAILABLE,
                                     cserve_page_language(&ns_site, r));
    const char *text = cserve_arena_printf(a, "%s\n", words);
    cserve_httpd_text(rep, 503, "text/plain; charset=utf-8", text,
                      strlen(text));
  } else if (cserve_header(rep, CSERVE_H_LOCATION, f->url) != 0) {
    ns_fail(rep);
  } else {
    cserve_httpd_text(rep, 303, NULL, "", 0);
  }
  cserve_arena_free(a);
}
