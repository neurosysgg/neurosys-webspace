/* The demos: data/demos.json read at start-up (DemoRepository), the gate
 * in front of each (DemoGate), its page (DemoView, with each track's
 * waveform sidecar) and its audio (DemoAudioController). A slug nobody
 * has and a wrong password are the same 401 and cost the same time. */
#include "views.h"

#include "file.h"
#include "json.h"
#include "login.h"
#include "password.h"
#include "reading.h"

#include <mbedtls/base64.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>

#define DEMOS_MAX 64
#define TRACKS_MAX 16

typedef struct {
  const char *label, *file;
  long long seconds;
} TRACK;

typedef struct {
  const char *slug, *title, *password;
  const char *description; /* NULL: "work in progress" */
  TRACK tracks[TRACKS_MAX];
  int ntracks;
} DEMO;

static CSERVE_JSON *g_json;
static DEMO g_demos[DEMOS_MAX];
static int g_ndemos;
static char g_dir[512]; /* data/demos */

/* what DemoTrack holds a label and a file name to: a label is
 * [a-z0-9][a-z0-9-]{0,31}, a file [A-Za-z0-9][A-Za-z0-9._-]{0,127} -- so
 * neither is ever a path */
static int named(const char *s, int upper_dot_underscore, size_t most) {
  size_t n = s ? strlen(s) : 0;
  if (n == 0 || n > most) {
    return 0;
  }
  for (size_t i = 0; i < n; i++) {
    char c = s[i];
    int ok = (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') ||
             (i > 0 && c == '-') ||
             (upper_dot_underscore &&
              ((c >= 'A' && c <= 'Z') || (i > 0 && (c == '.' || c == '_'))));
    if (!ok) {
      return 0;
    }
  }
  return 1;
}

static int load_demo(const char *slug, const CSERVE_JSON *v, DEMO *d, char *err,
                     size_t err_cap) {
  const CSERVE_JSON *tracks = cserve_json_get(v, "tracks");
  const CSERVE_JSON *desc = cserve_json_get(v, "description");
  d->slug = slug;
  d->title = cserve_json_str(cserve_json_get(v, "title"));
  d->password = cserve_json_str(cserve_json_get(v, "password"));
  d->description = cserve_json_str(desc);
  if (!d->title || !d->password || !tracks ||
      tracks->type != CSERVE_JSON_ARRAY || tracks->count == 0 ||
      tracks->count > TRACKS_MAX ||
      (desc && desc->type != CSERVE_JSON_NULL && !d->description)) {
    snprintf(err, err_cap, "demo %s: a title, a password and tracks", slug);
    return -1;
  }
  for (int i = 0; i < tracks->count; i++) {
    TRACK *t = &d->tracks[i];
    const CSERVE_JSON *row = &tracks->items[i];
    t->label = cserve_json_str(cserve_json_get(row, "label"));
    t->file = cserve_json_str(cserve_json_get(row, "file"));
    if (!named(t->label, 0, 32) || !named(t->file, 1, 128) ||
        cserve_json_whole(cserve_json_get(row, "seconds"), 0, 1 << 30,
                          &t->seconds) != 0) {
      snprintf(err, err_cap, "demo %s: track %d isn't one", slug, i);
      return -1;
    }
    for (int k = 0; k < i; k++) {
      if (!strcmp(d->tracks[k].label, t->label)) {
        snprintf(err, err_cap, "demo %s: two tracks labelled %s", slug,
                 t->label);
        return -1;
      }
    }
  }
  d->ntracks = tracks->count;
  if (!cserve_password_valid(d->password)) {
    /* fail closed and keep serving: its gate opens for nobody until
     * stage-demo --rotate mints it a digest this reads */
    fprintf(stderr,
            "neurosys: demo %s: a password digest c-serve doesn't read "
            "(bcrypt?): rotate it\n",
            slug);
  }
  return 0;
}

int ns_demos_load(const char *data, char *err, size_t err_cap) {
  char path[600];
  g_ndemos = 0;
  if (!data) {
    return 0; /* no data: no demos, every /demos/x a 401 */
  }
  snprintf(g_dir, sizeof g_dir, "%s/demos", data);
  snprintf(path, sizeof path, "%s/demos.json", data);
  FILE *f = fopen(path, "rb");
  if (!f) {
    return 0; /* none staged: DemoRepository's empty catalogue */
  }
  char *text = malloc(1 << 20);
  size_t n = text ? fread(text, 1, (1 << 20) - 1, f) : 0;
  int whole = text && feof(f);
  fclose(f);
  if (!whole) {
    free(text);
    snprintf(err, err_cap, "%s: unreadable, or past 1 MiB", path);
    return -1;
  }
  text[n] = '\0';
  char why[160];
  g_json = cserve_json_parse(text, why, sizeof why);
  free(text);
  if (!g_json || g_json->type != CSERVE_JSON_OBJECT ||
      g_json->count > DEMOS_MAX) {
    snprintf(err, err_cap, "%s: %s", path,
             g_json ? "not an object of demos" : why);
    ns_demos_free();
    return -1;
  }
  for (int i = 0; i < g_json->count; i++) {
    if (load_demo(g_json->keys[i], &g_json->items[i], &g_demos[i], err,
                  err_cap) != 0) {
      ns_demos_free();
      return -1;
    }
  }
  g_ndemos = g_json->count;
  return 0;
}

void ns_demos_free(void) {
  cserve_json_free(g_json);
  g_json = NULL;
  g_ndemos = 0;
}

/* DemoGate::enter(): the demo, when the request's credentials open it;
 * NULL with the 401 answered otherwise. Both comparisons run every time,
 * and a slug nobody has pays for a check against nothing. A wrong try is
 * slowed by the host's tarpit (login.h), the slug being the account --
 * PHP has none, so diff.sh diffs the app run with --no-tarpit and checks
 * the tarpit on a host of its own -- and a key
 * still waiting gets a 429 before any PBKDF2 runs. */
static const DEMO *enter(const CSERVE_HTTPD_REQ *r, CSERVE_ARENA *a,
                         const char *slug, CSERVE_HTTPD_REPLY *rep) {
  const char *auth = cserve_httpd_header(r, "Authorization");
  if (auth && cserve_login_check(r, slug, rep)) {
    return NULL;
  }
  const DEMO *d = NULL;
  for (int i = 0; i < g_ndemos; i++) {
    if (!strcmp(g_demos[i].slug, slug)) {
      d = &g_demos[i];
    }
  }
  char user[256], pass[512];
  cserve_basic_credentials(auth, user, sizeof user, pass, sizeof pass);
  int user_ok = strcmp(user, NS_DEMO_USER) == 0;
  int pass_ok = cserve_password_matches(
      d ? d->password : cserve_password_unmatchable(), pass, strlen(pass));
  if (d && user_ok && pass_ok) {
    cserve_login_pass(r, slug);
    return d;
  }
  char encoded[512];
  const char *realm = cserve_rawurlencode(slug, encoded, sizeof encoded) == 0
                          ? cserve_arena_printf(a, NS_NAME " demo: %s", encoded)
                          : NULL;
  if (!realm || cserve_basic_challenge(rep, realm) != 0) {
    ns_fail(rep);
  } else {
    cserve_httpd_text(rep, 401, "text/plain; charset=utf-8", "", 0);
  }
  if (auth) {
    cserve_login_fail(r, slug, rep);
  }
  return NULL;
}

/* m:ss, or NULL for a track whose length nobody wrote down */
static const char *duration(CSERVE_ARENA *a, const TRACK *t) {
  return t->seconds > 0 ? cserve_arena_printf(a, "%lld:%02lld", t->seconds / 60,
                                              t->seconds % 60)
                        : NULL;
}

/* the waveform beside a track (Waveform: "NSW1", then 512 columns of four
 * bytes), as the base64 of its columns; NULL without a good one */
static const char *peaks(CSERVE_ARENA *a, const char *slug, const TRACK *t) {
  unsigned char buf[2053];
  char path[700];
  snprintf(path, sizeof path, "%s/%s/%s.wave", g_dir, slug, t->label);
  FILE *f = fopen(path, "rb");
  size_t n = f ? fread(buf, 1, sizeof buf, f) : 0;
  if (f) {
    fclose(f);
  }
  if (n != 2052 || memcmp(buf, "NSW1", 4) != 0) {
    return NULL;
  }
  size_t len = 0;
  unsigned char *out = cserve_arena_alloc(a, 2800);
  if (!out || mbedtls_base64_encode(out, 2800, &len, buf + 4, n - 4) != 0) {
    return NULL;
  }
  return (const char *)out;
}

static CSERVE_NODE *track(CSERVE_ARENA *a, const DEMO *d, const TRACK *t) {
  const char *wave = peaks(a, d->slug, t), *time = duration(a, t);
  CSERVE_NODE *row = wave ? OWN(demo_waveform) : EL(div);
  row = CLASS(row, DEMO_TRACK);
  if (wave) {
    row = cserve_attr(a, row, &ns_attr_waveform_peaks, wave);
    if (t->seconds > 0) {
      row = cserve_attr(a, row, &ns_attr_waveform_duration,
                        cserve_arena_printf(a, "%lld", t->seconds));
    }
  }
  row = HAS(row, HAS(CLASS(EL(span), DEMO_LABEL), TEXT(t->label)));
  if (time) {
    row = HAS(row, HAS(CLASS(EL(span), DEMO_TIME), TEXT(time)));
  }
  CSERVE_NODE *audio = ATTR(FLAG(EL(audio), controls), preload, "none");
  audio = ATTR(audio, src,
               cserve_arena_printf(a, "/demos/%s/%s", d->slug, t->label));
  return HAS(row, audio);
}

static CSERVE_NODE *demo_view(CSERVE_ARENA *a, const DEMO *d) {
  const TRACK *latest = &d->tracks[0];
  const char *time = duration(a, latest);
  NS_TERMINAL *t = ns_terminal(a, "demo.log", 1, "./demo", "--play", d->title);
  ns_terminal_field(t, &T_TERMINAL_ARTIST, cserve_verbatim(a, NS_NAME),
                    NS_TONE_PLAIN);
  ns_terminal_field(
      t, &T_DEMO_MIXES,
      cserve_verbatim(a, cserve_arena_printf(a, "%d", d->ntracks)),
      NS_TONE_PLAIN);
  ns_terminal_field(
      t, &T_DEMO_LATEST,
      cserve_verbatim(
          a, time ? cserve_arena_printf(a, "%s  %s", latest->label, time)
                  : latest->label),
      NS_TONE_OK);
  ns_terminal_field(t, &T_TERMINAL_STATUS, &T_DEMO_UNRELEASED, NS_TONE_ERROR);
  CSERVE_LIST *tracks = cserve_list(a);
  for (int i = 0; i < d->ntracks; i++) {
    cserve_list_add(tracks, track(a, d, &d->tracks[i]));
  }
  const CSERVE_TR *desc = d->description ? cserve_verbatim(a, d->description)
                                         : &T_DEMO_WORK_IN_PROGRESS;
  CSERVE_NODE *info =
      HAS(CLASS(EL(section), DEMO_INFO), HAS(EL(h1), ns_accented(a, d->title)),
          HAS(CLASS(EL(p), TAGLINE), TEXT(NS_NAME " \xE2\x80\x94 "), TR(desc)),
          HAS(CLASS(EL(p), DEMO_NOTICE), TR(&T_DEMO_NOTICE)),
          HAS(CLASS(EL(div), DEMO_TRACKS), cserve_list_node(tracks)));
  return cserve_fragment(
      a, HAS(CLASS(EL(section), DEMO_HERO), ns_terminal_element(t)), info);
}

/* never kept, never indexed: what a demo's page and audio say about
 * themselves */
static int hidden(CSERVE_HTTPD_REPLY *rep) {
  return cserve_header(rep, CSERVE_H_X_ROBOTS_TAG,
                       "noindex, nofollow, noarchive");
}

void ns_demo(const CSERVE_HTTPD_REQ *r, const char *body,
             const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/demos/{slug}", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  const DEMO *d = enter(r, a, cserve_route_param(&own, "slug"), rep);
  if (d) {
    if (cserve_header(rep, CSERVE_H_CACHE_CONTROL,
                      cserve_cache_value(CSERVE_CACHE_NO_STORE)) != 0 ||
        hidden(rep) != 0) {
      ns_fail(rep);
    } else {
      ns_page(r, a, ns_title(a, cserve_verbatim(a, d->title)), demo_view(a, d),
              200, rep);
    }
  }
  cserve_arena_free(a);
}

/* MimeType::forAudio(): the types a demo's audio may be; NULL for any
 * other (phpanta throws: a 500) */
static const char *audio_type(const char *file) {
  static const char *const TYPES[][2] = {
      {"mp3", "audio/mpeg"},  {"m4a", "audio/mp4"}, {"mp4", "audio/mp4"},
      {"flac", "audio/flac"}, {"wav", "audio/wav"}, {"ogg", "audio/ogg"},
      {"opus", "audio/opus"},
  };
  const char *dot = strrchr(file, '.');
  for (size_t i = 0; dot && i < sizeof TYPES / sizeof *TYPES; i++) {
    if (!strcasecmp(dot + 1, TYPES[i][0])) {
      return TYPES[i][1];
    }
  }
  return NULL;
}

void ns_demo_audio(const CSERVE_HTTPD_REQ *r, const char *body,
                   const CSERVE_ROUTE_MATCH *m, CSERVE_HTTPD_REPLY *rep) {
  (void)body;
  (void)m;
  CSERVE_ARENA *a = cserve_arena_new();
  CSERVE_ROUTE_MATCH own;
  if (!a) {
    ns_fail(rep);
    return;
  }
  if (!ns_match(a, r, "/demos/{slug}/{label}", &own)) {
    ns_not_found(r, a, rep);
    cserve_arena_free(a);
    return;
  }
  const DEMO *d = enter(r, a, cserve_route_param(&own, "slug"), rep);
  const TRACK *t = NULL;
  for (int i = 0; d && i < d->ntracks; i++) {
    if (!strcmp(d->tracks[i].label, cserve_route_param(&own, "label"))) {
      t = &d->tracks[i];
    }
  }
  if (d && !t) {
    ns_not_found(r, a, rep);
  } else if (t) {
    const char *type = audio_type(t->file);
    const char *path =
        cserve_arena_printf(a, "%s/%s/%s", g_dir, d->slug, t->file);
    if (!type) {
      ns_fail(rep);
    } else if (cserve_file_reply(r, path, type, rep) != 0) {
      if (!rep->status) {
        ns_not_found(r, a, rep);
      }
    } else if (hidden(rep) != 0) {
      ns_fail(rep);
    }
  }
  cserve_arena_free(a);
}
