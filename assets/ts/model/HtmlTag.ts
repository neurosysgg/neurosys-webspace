/**
 * Mirrors NeuroSYS\View\Html\HtmlTag — the standard elements this site emits.
 *
 * The client creates most of these: the gate builds a <p>, a <button> and a <small>, the player an
 * <iframe> and a <div>, and Navigation a <textarea> to decode entities out of a title.
 */
export enum HtmlTag {
  Html     = 'html',
  Head     = 'head',
  Meta     = 'meta',
  Link     = 'link',
  Title    = 'title',
  Script   = 'script',
  Body     = 'body',
  Header   = 'header',
  Nav      = 'nav',
  Main     = 'main',
  Footer   = 'footer',
  Section  = 'section',
  H1       = 'h1',
  H2       = 'h2',
  H3       = 'h3',
  P        = 'p',
  Br       = 'br',
  A        = 'a',
  Img      = 'img',
  Button   = 'button',
  Span     = 'span',
  Small    = 'small',
  Strong   = 'strong',
  Div      = 'div',
  Iframe   = 'iframe',
  Textarea = 'textarea',
  Table    = 'table',
  Tr       = 'tr',
  Td       = 'td',
}
