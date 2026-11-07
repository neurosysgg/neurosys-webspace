/* View/Terminal/: the <terminal-window> a page opens with -- a label, a
 * command as a shell would show it, and its rows as the JSON the element
 * reads, in the language of the page (TerminalFields) */
#include "views.h"

#include "reading.h"

#include <stdarg.h>
#include <stdio.h>
#include <string.h>

#define FIELDS_MAX 12

struct NS_TERMINAL {
  CSERVE_ARENA *a;
  const char *label, *command;
  int narrow, n;
  struct {
    const CSERVE_TR *key, *value;
    NS_TONE tone;
  } f[FIELDS_MAX];
};

static const char *const TONES[] = {"plain", "ok", "error"};

/* an argument as TerminalCommand::token() writes it: '-' first as it is,
 * anything else in "", its \ and " escaped */
static void token(CSERVE_ARENA *a, char **cmd, const char *arg) {
  if (arg[0] == '-') {
    *cmd = cserve_arena_printf(a, "%s %s", *cmd, arg);
    return;
  }
  size_t n = strlen(arg), k = 0;
  char *q = cserve_arena_alloc(a, 2 * n + 1);
  if (!q) {
    return;
  }
  for (size_t i = 0; i < n; i++) {
    if (arg[i] == '\\' || arg[i] == '"') {
      q[k++] = '\\';
    }
    q[k++] = arg[i];
  }
  *cmd = cserve_arena_printf(a, "%s \"%s\"", *cmd, q);
}

NS_TERMINAL *ns_terminal_(CSERVE_ARENA *a, const char *label, int narrow,
                          const char *program, ...) {
  NS_TERMINAL *t = cserve_arena_alloc(a, sizeof *t);
  if (!t) {
    return NULL;
  }
  t->a = a;
  t->label = label;
  t->narrow = narrow;
  char *cmd = cserve_arena_strdup(a, program);
  va_list ap;
  va_start(ap, program);
  for (const char *arg; (arg = va_arg(ap, const char *));) {
    token(a, &cmd, arg);
  }
  va_end(ap);
  t->command = cmd;
  return t;
}

void ns_terminal_field(NS_TERMINAL *t, const CSERVE_TR *key,
                       const CSERVE_TR *value, NS_TONE tone) {
  if (!t) {
    return;
  }
  if (t->n == FIELDS_MAX) {
    cserve_arena_fail(t->a, "a terminal with more rows than it holds");
    return;
  }
  t->f[t->n].key = key;
  t->f[t->n].value = value;
  t->f[t->n++].tone = tone;
}

/* s as json_encode() writes a string under JSON_UNESCAPED_UNICODE and
 * JSON_UNESCAPED_SLASHES: " and \ escaped, a control character as \b \f
 * \n \r \t or \u00xx, U+2028 and U+2029 as   and  , all else as
 * it is; text that isn't UTF-8 fails the arena, as json_encode() throws */
static const char *json(CSERVE_ARENA *a, const char *s) {
  size_t n = strlen(s), k = 0;
  char *out = cserve_arena_alloc(a, 6 * n + 3);
  if (!out) {
    return "";
  }
  if (!cserve_utf8_valid(s, n)) {
    cserve_arena_fail(a, "a terminal row that isn't UTF-8");
    return "";
  }
  out[k++] = '"';
  for (size_t i = 0; i < n; i++) {
    unsigned char c = (unsigned char)s[i];
    const char *esc = c == '"'    ? "\\\""
                      : c == '\\' ? "\\\\"
                      : c == '\b' ? "\\b"
                      : c == '\f' ? "\\f"
                      : c == '\n' ? "\\n"
                      : c == '\r' ? "\\r"
                      : c == '\t' ? "\\t"
                                  : NULL;
    if (esc) {
      k += (size_t)snprintf(out + k, 3, "%s", esc);
    } else if (c < 0x20) {
      k += (size_t)snprintf(out + k, 7, "\\u%04x", c);
    } else if (c == 0xE2 && (unsigned char)s[i + 1] == 0x80 &&
               ((unsigned char)s[i + 2] == 0xA8 ||
                (unsigned char)s[i + 2] == 0xA9)) {
      k +=
          (size_t)snprintf(out + k, 7, "\\u%04x",
                           (unsigned)(0x2000 + (unsigned char)s[i + 2] - 0x80));
      i += 2;
    } else {
      out[k++] = (char)c;
    }
  }
  out[k++] = '"';
  out[k] = '\0';
  return out;
}

/* the rows in language lang, as TerminalFields::in() encodes them */
static const char *rows(NS_TERMINAL *t, int lang) {
  CSERVE_ARENA *a = t->a;
  const char *s = "[";
  for (int i = 0; i < t->n; i++) {
    s = cserve_arena_printf(
        a, "%s%s{\"key\":%s,\"value\":%s,\"tone\":\"%s\"}", s, i ? "," : "",
        json(a, cserve_tr_in(a, t->f[i].key, lang)),
        json(a, cserve_tr_in(a, t->f[i].value, lang)), TONES[t->f[i].tone]);
  }
  return cserve_arena_printf(a, "%s]", s);
}

CSERVE_NODE *ns_terminal_element(NS_TERMINAL *t) {
  if (!t) {
    return cserve_nothing();
  }
  CSERVE_ARENA *a = t->a;
  /* a translatable resolved at render: the rows written out in each of
   * the site's languages now, the right one picked there */
  CSERVE_TR *fields = cserve_arena_alloc(a, sizeof *fields);
  if (!fields) {
    return cserve_nothing();
  }
  fields->kind = CSERVE_TR_CATALOG;
  for (int l = 0; l < ns_site.languages->n; l++) {
    fields->in[l] = rows(t, l);
  }
  CSERVE_NODE *el =
      cserve_attr(a, OWN(terminal_window), &ns_attr_terminal_label, t->label);
  el = cserve_attr(a, el, &ns_attr_terminal_command, t->command);
  el = cserve_attr_tr(a, el, &ns_attr_terminal_fields, fields);
  return cserve_attr_bool(a, el, &ns_attr_terminal_narrow, t->narrow);
}
