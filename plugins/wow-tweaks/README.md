# WOW Tweaks

Small adjustments to how WordPress behaves on this site. The kind of thing
that is usually a half-remembered line in `wp-config.php` that nobody dares
touch because nobody remembers putting it there.

One screen, under **Settings → Site tweaks**, with a section per tweak.

## Revisions

WordPress keeps every version of every post for ever. On a site whose pages
are galleries, a version is not a few paragraphs of text: it is the whole
arrangement of every picture on the page — a hundred of them with their
addresses and their framing — written out again each time anybody nudges
anything.

Before this was set, four album pages here held 58, 54, 42 and 22 versions:
223 revisions in all, 5.3 MB of content in a 15.3 MB database.

They are also not only weight. Each one names the pictures under the names
they had when it was saved, so converting the library leaves dozens of old
versions pointing at files that no longer exist. Nothing shows them to a
visitor, but everything that walks the posts walks them too.

The setting takes effect in both directions: WordPress keeps the newest few
from then on, **and** lowering the number deletes what is already over it, at
the moment the setting is saved. The screen says how many that will be before
you save, and it cannot be undone.

Autosaves are never touched. There is at most one per person per post and
WordPress needs it to offer "restore the backup of this post".

    wp wow-tweaks prune-revisions --dry-run
    wp wow-tweaks prune-revisions

Leaving the field empty keeps every version, which is what WordPress does on
its own. Zero switches versions off altogether.

## Where things are

| | |
|---|---|
| `includes/options.php` | the settings |
| `includes/revisions.php` | the revision limit, and applying it to what is already there |
| `includes/admin.php` | the settings screen |
| `includes/cli.php` | the commands |
