# WOW Media Folders

Folders for the media library, filled in from albums.

## What it provides

| | |
| --- | --- |
| Taxonomy key | `wow_media_folder` |
| Applies to | `attachment` |
| Shape | flat, one level |
| Filter parameter | `wow_media_folder=<slug>`, or `wow_folder_none=1` |
| REST | `GET /wp/v2/media?wow_media_folder=<term_id>` |

Folders are terms, not directories. Files stay exactly where WordPress put
them in `uploads/<year>/<month>`, so a folder can be renamed or deleted
without breaking a single URL, and deleting one never deletes a file.

## The panel

A sidebar sits beside the library in both views, listing every folder with
the number of files in it, plus "All files" and "No folder" at the top and a
search box that narrows the list as you type. Clicking a folder shows its
contents; in the grid that happens without a page load, in the list view the
entry is an ordinary link.

The search compares folded text: lowercase and stripped of accents, against
both the name and the slug. Folder names here come from album titles, which
are French, and nobody reaches for the accents when looking something up —
"creation" has to find "création de jardins". "All files" and "No folder"
step aside while a search is running, since neither is a folder.

`upload.php` carries no action hook anywhere inside its page body, so the
panel is printed on `all_admin_notices` — the last hook that still fires
inside `#wpbody-content` — and the script lifts it into place. With
JavaScript off it stays where it was printed: a list of links above the
library, which still filters, just without the layout.

## In the editor's media modal

The modal gets a **Filter by folder** dropdown next to core's own "Images /
Audio / Video" one: "All folders", "No folder", then every folder with the
number of files in it. Picking one narrows the library on the spot.

It is a dropdown rather than the panel because the modal is a Backbone
application that redraws its toolbar whenever it changes state; markup
dropped into it from outside disappears the next time that happens. The
dropdown is built the way core builds its own, added to the same toolbar, and
so survives every redraw.

The media library screen does not get it — it has the panel, and two controls
filtering the same list would only disagree with each other. Nor does a
browser without filters, such as the list of files already attached to the
post.

## Filling folders in from albums

Saving an album files its images into a folder named after it. The folder is
tied to the album by the term meta `wow_album_id` rather than by name, so
renaming the album renames the folder instead of leaving a second one next to
it. The slug is left alone on purpose: filter links are built from it.

Divider strips count here, unlike in the album image counter. A strip is not
work on display, but its picture still belongs to the album. The album cover
is included too.

**Nothing you did by hand is ever undone.** Each attachment carries a
`_wow_media_folder_auto` list of the folders this plugin assigned by itself,
and a folder is only ever taken off a file if it is on that list. Filing a
file by hand — by dragging it, by the bulk action, or by the dropdown in its
details — clears that list, and from then on the album automation leaves that
file alone.

**An album's folder lives and dies with the album.** Deleting an album for
good takes its folder with it; the files stay in the library and simply stop
carrying that label. Trashing is not enough — a trashed album can be
restored, and its folder holds filing that would otherwise have to be done
again by hand.

Opening "Add New Album" and walking away creates nothing: an auto-draft is
skipped, so the placeholder title WordPress gives it never becomes a folder
name. (Several such folders existed before this rule; `wp wow-folders prune`
lists folders whose album is gone and removes them with `--delete`.)

The dependency on wow-blocks is soft, through `function_exists()`. Without it
the automation stays off and everything else keeps working.

## Filing files by hand

- **Dragging.** Drag a tile onto a folder in the panel. Dragging one of
  several selected tiles carries the whole selection, the way a file manager
  behaves. Dropping on "No folder" takes the files out of every folder.
- **Bulk action** in the list view: "Move to …" per folder, plus "Take out of
  every folder".
- **A dropdown** in the attachment details, in the grid and in the editor's
  media modal alike. One file, one folder — the same thing the drop and the
  bulk action do, so no corner of the interface offers a state the others
  cannot express. Picking "No folder" takes the file out.

A drop means "put it here", not "add it here as well": the file ends up in
exactly the folder it was dropped on.

The one way a file can still hold two folders is the album sync, which adds
rather than replaces, so a picture used by two albums is filed under both.
The dropdown then shows the first of them, and saving settles on it.

## Three things core does that had to be worked around

**Term counts.** Left to itself WordPress picks `_update_post_term_count()`,
which counts attachments under `post_status = 'inherit' AND post_parent > 0`
(`wp-includes/taxonomy.php:4232`). Files never attached to a post are the
majority of any library — all 45 in this one — so every folder would have
reported zero while holding dozens of images. The taxonomy is registered with
`'update_count_callback' => '_update_generic_term_count'`, which simply counts
rows in `term_relationships`.

**The grid filter needs no PHP.** `wp_ajax_query_attachments()` trims the
request down to a whitelist, but first it adds the `query_var` of every
attachment taxonomy to that list
(`wp-admin/includes/ajax-actions.php:3083-3089`). So the slug travels from the
collection's props all the way to `WP_Query` untouched. This is also why
`query_var` must stay on: turning it off would mean writing that plumbing by
hand. Note that core keeps the query var for a non-public taxonomy **only in
the admin** (`class-wp-taxonomy.php:374`), which is the only place it is
needed — and why checking for it from WP-CLI reports `false`.

**The details field is not named after the taxonomy.** After running
`attachment_fields_to_save`, `wp_ajax_save_attachment_compat()` walks every
taxonomy on the attachment and feeds whatever was submitted under that name
into `preg_split()` (`ajax-actions.php:3286`), which would break the moment a
folder name contains a comma — which album titles do. Under the name
`wow_folders` core leaves the field alone.

## Commands

```bash
# file every album's images; needed once, for a library that predates the plugin
wp wow-folders sync

# list folders whose album is gone; add --delete to actually remove them
wp wow-folders prune
```

## Layout

```
wow-media-folders.php       loading, activation
includes/taxonomy.php       the taxonomy, and tying a folder to an album
includes/sync.php           filling folders in, and leaving manual work alone
includes/query.php          the "no folder" filter, for both roads into the library
includes/admin-sidebar.php  the panel and its assets
includes/admin-list.php     dropdown, bulk action, details folder picker
includes/ajax.php           create, rename, delete, move
includes/cli.php            sync and prune
assets/folders.js           panel, filtering, dragging
assets/folders.css
```

No build step: plain JS, no bundler.

## Translations

Source strings are English. Translations live in `languages/`, one `.po` and
`.mo` pair per locale. The panel's strings reach the script through
`wp_localize_script()`, so there are no JSON translation files to generate.

```bash
# refresh the template after changing any string
wp i18n make-pot . languages/wow-media-folders.pot --domain=wow-media-folders

# after editing a .po
wp i18n make-mo languages
```

## Settled

- **Folders are flat.** One album, one folder. Nesting would change the
  filter, the panel and the automation all at once, for a library that is
  organised by album and nothing else.
- **The panel itself is not in the editor's media modal**, only on the media
  library screen. The modal filters through a dropdown instead, and the
  folder picker in the attachment details is there as well.
- **Files never move on disk.** A folder is a label.
