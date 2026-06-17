# 2026/06/17 - initial review

## general:
- framework for basic website is there, works
- basic styling is there, works, looks good
- cover art has been finalized => replacing placeholder during review
- fix PHPStorm Warnings; genric cleanup
- double checked; set strato PHP to 8.5; using 8.5 features
- css, .md, .htaccess, .git\[.]* files not audited

## changes during review:
- added cover_url to releases array; links to the cover on hidrive via direct-download link
  - removed /img directory (no use now)
- added private soundcloud link
- only FLAC for now (late night; couldnt get ffmpeg to transcode within 3 minutes)
- fixed / workarounded broken soundcloud embed (just use the 1:1 embed HTML from SC for now)
- fixed broken download logic (added data-no-spa attribute to download links)
- removed /index from links; unnecessary, properly handled and cleaner
- TODO: classes etc; proper cleanup round
- minor text/colour changes

## next steps:
- strato FTP setup
  - deploy script?
  - test.neurosys.gg subdomain?
- refactoring/cleanup/modularization/etc as boredom dictates
- testing