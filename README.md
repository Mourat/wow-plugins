# WOW Plugins

The plugins written for the Wow-arts sites, in one place, releasing
themselves to the sites that run them.

| | |
|---|---|
| `wow-albums` | the album post type and its links |
| `wow-back-to-top` | a button back to the top of the page |
| `wow-blocks` | the row, the strip and the album grid |
| `wow-contact-form` | a contact form that keeps what it is sent |
| `wow-guard` | the security plugin: guessing, two-step login, updates |
| `wow-images` | what happens to a picture after it is uploaded |
| `wow-mail` | sending mail, and finding out whether it went |
| `wow-map` | a map drawn once and served as a picture |
| `wow-media-folders` | folders in the media library |
| `wow-tweaks` | the small changes every site here wants |
| `wow-updates` | how all of the above reach the sites |

Each has its own README, and that is where the reasoning for it lives.

## Making a release

    git tag v0.2.0
    git push origin v0.2.0

That is the whole of it. The workflow in `.github/workflows/release.yml`
builds every plugin that has something to build, zips each one, writes the
file the sites read and publishes the release.

**The tag is only a name.** What a site compares against what it has
installed is the `Version:` header inside each plugin, so a plugin that
changed needs its own version raised in its own main file. One that did not
change can be left alone: it goes into the release at the version it already
had, and no site will see anything to do.

## How a site gets them

It installs `wow-updates` and nothing else. Every plugin here carries

    Update URI: https://github.com/Mourat/wow-plugins

in its header, which is all WordPress needs to ask who looks after it;
`wow-updates` answers, and WordPress does the rest through the machinery it
already has. With automatic plugin updates switched on — `wow-guard` has the
switch — nobody has to press anything.

## Working on a plugin

The copy that runs on a site is the copy in that site's `wp-content/plugins`.
This repository is where the released one lives. The two are the same files;
keeping them so is a matter of copying one way before a release and letting
the updater copy the other way after it.

`wow-blocks` is the only one with a build step:

    cd plugins/wow-blocks
    npm ci
    npm run build

`build/` is not committed — the workflow makes it — so a fresh checkout of
that plugin does nothing until it has been built once.
