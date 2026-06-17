# notes:
- i'd add an HTTPStatusCode enum for responses
- PHPStorm complains about namespaces not matching PSR4/0 and wants to re-do the double nesting (neuroSYS/neuroSYS/)
- added #\[NoReturn], :never return-type, readonly where applicable
- we need at least some light phpdoc+comments on everything. "This is the x class. it does x."; "constructs an instance of  {@link self}" etc etc. WHERE APPLICABLE -> more descriptive comments.
- feels wrong for the router to directly have $releases IMO. this should be handled at a different stage, no? Once we know we're routing to releases, we get the data for the releases.
  - This part should be built off of Collection(Release::class, ...), not a plain array.