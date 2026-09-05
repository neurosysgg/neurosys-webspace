/**
 * Mirrors NeuroSYS\Http\RequestHeader — the request headers the server reads.
 */
export enum RequestHeader {
  RequestedWith = 'X-Requested-With',

  /**
   * Written by no client code — the browser sends it on its own, from the ETag the server gave it.
   * Named here only so this mirror stays comparable case for case, the way EmbedAttribute.Loaded
   * has a PHP case it is never emitted from.
   */
  IfNoneMatch = 'If-None-Match',
}
