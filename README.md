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

    bin/bump.sh --apply
    git commit -am "What changed"
    git tag v0.2.0
    git push origin main --follow-tags

**The tag is only a name.** What a site compares against what it has
installed is the `Version:` header inside each plugin. A plugin that changed
and kept its version reaches nobody, and that shows up days later as "the
site did not update", looking like a fault in the updater — which is why the
first line is not left to memory.

`bin/bump.sh` asks git what changed under `plugins/` since the last tag and
raises those plugins by a patch, in the header and in the plugin's own
`_VERSION` constant both. Run without `--apply` it only says what it would
do. `--minor` and `--major` step further along; `--all` raises everything,
changed or not. Run twice before tagging it does nothing the second time:
one release is one step.

A plugin that did not change is left alone. It still goes into the release at
the version it already had, so every plugin is always downloadable from the
newest release, and no site sees anything to do about it.

The workflow in `.github/workflows/release.yml` does the rest: builds every
plugin that has something to build, zips each one, writes the file the sites
read and publishes the release.

## How a site gets them

It installs `wow-updates` and nothing else. Every plugin here carries

    Update URI: https://github.com/Mourat/wow-plugins

in its header, which is all WordPress needs to ask who looks after it;
`wow-updates` answers, and WordPress does the rest through the machinery it
already has. With automatic plugin updates switched on — `wow-guard` has the
switch — nobody has to press anything.

## Working on a plugin

The copy that runs on a site is the copy in that site's `wp-content/plugins`.
This repository is where the released one lives. The two are the same files:
code comes back from the site before a release, and the updater carries it
out to every site afterwards.

    bin/sync.sh ~/projects/ArleaMH/wp-content/plugins
    bin/sync.sh ~/projects/ArleaMH/wp-content/plugins wow-blocks

Code travels that way; versions do not. A site that has not taken the last
release yet is still carrying an older number, and a plain copy brings that
number back with it — after which the next release goes out below one already
published, every site finds it no newer than what it has, and the work sits
in a release nobody can see. `bin/sync.sh` therefore keeps whatever version
this repository has committed, and says when it did. A version raised on the
site on purpose — higher than the one here — is left alone.

It also mentions any `wow-` plugin the site has and this repository does not,
without copying it: adding a plugin is a decision, not a side effect.

`wow-blocks` is the only one with a build step:

    cd plugins/wow-blocks
    npm ci
    npm run build

`build/` is not committed — the workflow makes it — so a fresh checkout of
that plugin does nothing until it has been built once.
