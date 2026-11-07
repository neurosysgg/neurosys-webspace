#ifndef NS_MODEL_H
#define NS_MODEL_H

#include "text.h"

/* What the site knows, as tools/app-data.php prints it into gen/: the
 * releases and profiles compiled in (public anyway), with every value a
 * page shows already worked out -- a section's timestamp, the time spent,
 * a link's URL -- so the app holds data, not the model that made it. A
 * table ends in a row of zeros. */

/* what a download card says under a format's name */
typedef enum {
  NS_META_LOSSLESS,
  NS_META_LOSSY,
  NS_META_MP3,
  NS_META_OGG,
  NS_META_STEMS,
} NS_META;

typedef struct {
  const char *type;  /* "flac": the address's and the card's */
  const char *label; /* "FLAC" */
  NS_META meta;
  const char *url; /* where the file is; NULL: not uploaded yet (503) */
} NS_FORMAT;

typedef struct {
  const char *kind; /* "drop"; NULL: none */
  const char *time; /* "0:42" */
  const char *label;
} NS_SECTION;

/* a SoundCloud player's attributes, as SoundCloudEmbed writes them */
typedef struct {
  const char *track_id, *permalink;
  const char *secret; /* NULL: a public track */
  const char *style, *options, *height;
} NS_EMBED;

typedef struct {
  const char *slug, *title;
  int bpm;
  const CSERVE_TEXT *key;
  const char *genre;
  const CSERVE_TR *description;
  const char *cover;     /* its URL, or the placeholder's */
  const char *time;      /* the time spent; NULL: not said */
  const char *made_with; /* the plugins, joined; NULL: none */
  const NS_EMBED *embed; /* NULL: none */
  const NS_SECTION *sections;
  int nsections;
  const NS_FORMAT *formats;
  int nformats;
} NS_RELEASE;

typedef struct {
  const char *url;
  const CSERVE_TEXT *label;
  const char *icon, *height;
} NS_PROFILE;

extern const NS_RELEASE ns_releases[];
extern const int ns_nreleases;
extern const NS_PROFILE ns_profiles[];
extern const int ns_nprofiles;

#endif
