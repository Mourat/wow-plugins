# WOW Blocks

Row blocks for galleries built on a fixed grid.

## Blocks

| Block | What it does |
| --- | --- |
| `wow/media-row` | A row of one, two or three columns. Each column holds an image or a solid colour fill. |
| `wow/media-band` | A narrow divider strip: a fill plus an image on the left or the right. |
| `wow/album-grid` | Album tiles on the front page: cover, title and image count. |
| `wow/album-list` | A floating list linking to every album, for the album page. |

The `wow/media-row` layouts are exposed as variations, so the inserter shows
"1 column", "2 columns, narrow on the left", "2 columns, narrow on the right"
and "3 columns" directly.

## Geometry

Every number lives in `includes/config.php` and travels from there into the
CSS custom properties and into `window.wowBlocksConfig` for the editor. That
file is the only place to change them.

| Quantity | Value |
| --- | --- |
| Row width | 754 px |
| Height of an image row | 350 px |
| Height of the narrow strip | 97 px |
| Base gap | 16 px |
| Space above the narrow strip | 32 px |
| Image width in the narrow strip | 105 px (configurable per block) |

Column widths follow from the width and the gap:

```
narrow = (754 - 2 * 16) / 3 = 240   (rounded down)
wide   = 754 - 16 - 240     = 498
```

A third does not divide evenly at every gap, so the narrow column rounds
down: WordPress does not create a crop larger than the source, and the image
is then silently replaced by something small. A fraction of a pixel goes
unnoticed; a missing crop does not.

The gap is the same horizontally and vertically. The narrow strip is set off
from above by a larger gap, because it splits the gallery into themed parts.
Inside the strip itself the image and the fill meet edge to edge, as in the
design.

In CSS the widths are computed with `calc()`, so changing the gap leaves the
row exactly 754 px wide and the narrow column exactly a third of it.

## Why this rather than core/columns

The cells sit in a single array attribute; `InnerBlocks` is not used at all.
It was precisely the `core/columns` + `core/image` nesting that made the
column settings vanish from the inspector on pages with many elements. Here a
row is one block and its state is one object.

The selected cell's settings live in the inspector rather than on the canvas,
which keeps the canvas light even on a page with a hundred images.

## Image sizes

The plugin registers its own crops for each column type:

| Slug | Size | Crop |
| --- | --- | --- |
| `wow-col-full` / `-2x` | 754×350 / 1508×700 | hard |
| `wow-col-wide` / `-2x` | 498×350 / 996×700 | hard |
| `wow-col-narrow` / `-2x` | 240×350 / 480×700 | hard |
| `wow-band-src` / `-2x` | 754 / 1508 by width | soft |

1x and 2x share a ratio, and WordPress assembles the `srcset` from them by
itself. The image width in the narrow strip is arbitrary, so a hard crop does
not fit there: it is resized by width only and framed in CSS through
`object-fit` and the focal point. The file is tiny either way.

Framing is set with a focal point (`FocalPointPicker`) and ends up in
`object-position`.

The plugin's sizes are hidden from the "Image size" dropdown so nobody picks
one by accident for a regular image.

When an attachment lacks the requested crop, the renderer serves the
original. That happens in two cases: the source is smaller than the crop, or
the source matches the crop exactly, in which case WordPress creates no copy.
Without this check WordPress would substitute an arbitrary intermediate size,
usually a good deal smaller than intended.

### After editing a picture in the media editor

Cropping an image writes a new source file, `<name>-e<timestamp>.jpg`, and
regenerates the crops that still fit inside it. The ones that no longer fit —
a 498 px crop cannot come out of a 232 px picture — cannot be rebuilt, and
WordPress leaves their old entries in the metadata, still pointing at files
cut from the picture as it was **before** the edit. The editor shows the URL
saved in the block and the front end resolves the attachment, so the two show
different photos and it looks like a caching problem.

The renderer therefore checks the name of each size entry against the current
source file and falls back to the original when they disagree. The same rule
prunes the `srcset` through `wp_calculate_image_srcset`, otherwise a wide
screen would be served the pre-edit picture while a narrow one gets the
edited file. Nothing is written to the database and no file is deleted: the
leftover entries are simply ignored.

The same edit leaves a second trail. A block stores the URL it was handed
when the image was placed, and the media editor's new file has a new name, so
that URL goes out of date while the front end — which renders the attachment
by id — moves on without it. The canvas then showed the picture as it was
before the crop, and the framing aimed at it landed elsewhere on the page.
`src/shared/attachment-url.js` asks the REST API for the attachment's current
address, once per attachment per screen, so both sides draw the same file.
A URL that changes while the editor is open still wins: that only happens
when the media modal hands over a picture the person has just chosen.

### An image deleted from under a block

The media modal opened from "Replace image" arrives with the block's own
image selected and "Delete permanently" in its sidebar. The delete goes
through, but nothing tells the modal to redraw: the thumbnail stays, a second
click on it asks the server to delete something that is no longer there, and
WordPress answers "Error in deleting the attachment." — which reads as though
the first delete had failed, when it had not. The block, meanwhile, keeps the
id and the URL of an image that no longer exists, and the loss shows up as a
white gap on the published page, usually much later.

`src/shared/attachment-deleted.js` listens for the deletion and then checks
it. Telling a real one apart is the hard half. Opening the modal makes
Gutenberg fire a `destroy` for every model it is about to reload — see
`updateCollection()` in @wordpress/media-utils — so the event proves nothing
by itself; and the `destroyed` flag the model carries is no help either,
because core sets it in a `done` handler attached after the one Backbone uses
to announce the destruction, so at the moment the event arrives the flag is
still unset. The server is the only thing that knows, so the server is asked.
On a 404 the cell is emptied and the modal closed, which is also what clears
the stale thumbnail, since the next open rebuilds the collection from the
server. Closing the modal asks once more, in case the event was never heard.

The other half of the same problem is older than any one delete: a block may
already be holding an id that went away while nobody was looking.
`attachment-url.js` reports that as well, and the cell then draws nothing and
offers its picker again. Only WordPress saying outright that there is no such
attachment counts; being offline, a server error or a missing permission
leave the saved URL in place, because none of them is a reason to tell
someone their image has disappeared. The attributes are not touched until
something is actually chosen.

### Editing the file from the block

Cropping and rotating live in core's image editor, and the only route there
used to be the long way round: replace image, find in the library the one
already chosen, "Edit image", come back. "Edit image" in the inspector goes
straight there. It rides on the frame "Replace image" opens, which is why it
is handed that button's `open`: core's `MediaFrame.Select` already carries an
`edit-image` state and knows how to render it — the link in the modal's own
sidebar uses nothing else — so the button only opens the frame, puts the
attachment in that state and switches to it. The model is fetched first,
because the editor is opened with the attachment's `edit` nonce and a model
taken by id alone holds nothing but the id. A crop comes back under a new
name, so when the modal closes what was known about the attachment is thrown
away and the cell asks again.

### Who decides on lazy loading

Nobody here. Every cell used to be printed with `loading="lazy"`, which looks
like diligence and is the opposite: WordPress works the question out per
image in `wp_get_loading_optimization_attributes()`, leaving lazy loading off
the first few pictures of a page and marking the likeliest of them
`fetchpriority="high"`. That one is nearly always the largest thing in the
first screen, which is what Google times the page by. An image that arrives
already marked lazy is read as "not in the viewport", the reasoning stops
there, and the picture at the very top of an album queued behind the hundred
and eighty below it.

So the attribute is simply not set, and core answers for itself. On an album
page that moves `fetchpriority="high"` onto the first row's image, where it
belongs. For a cell holding a bare URL rather than an attachment it is still
stated outright: such an image never passes through core, so there is nothing
to work anything out with.

### Derived attributes and the undo stack

The cells array is kept exactly as long as the layout has columns, and that
trimming is done by the block rather than by the person. Written as a change
of its own it broke undo outright: the layout came from one dispatch and the
cells from the next, so undo took back the cells, left the layout as it was,
and the block put the cells straight back. Nothing moved on screen, a fresh
entry was pushed and the redo stack thrown away — after which nothing at all
could be undone, in that block or anywhere else on the page.

`useBlockAttributes` therefore takes `{ ownStep: false }` for a write that is
only the tail of another change, and calls
`__unstableMarkNextChangeAsNotPersistent()` before dispatching. The two then
sit in history as one step and undo takes them back together. Any write a
person actually asked for — a replaced image, a dropped file — stays a step
of its own.

What no code can repair is the framing itself. A focal point is a fraction of
the picture, `0.3` of its width and `0.41` of its height, and nothing records
where inside the old picture the new one was cut from. After a crop the same
fraction points at something else, and the framing has to be set again.

**Note:** images already in the library do not get the new crops until they
are regenerated:

```bash
docker compose --profile tools run --rm wpcli media regenerate --yes
```

## Dragging images in

The cells of the image row and the image slot of the narrow strip accept
drag and drop. There are two branches, and it is the browser that tells them
apart, not us.

**A file from disk.** `dataTransfer.files` holds a real file, which goes to
`mediaUpload` from the editor settings: an ordinary upload with every size.

**An image from another site.** Here there is no file: between tabs the
browser passes only `text/html` or `text/uri-list`, that is, a URL. That URL
goes to `POST /wp/v2/media` with a `url` parameter, an endpoint added in
WP 7.1 that fetches the image server-side through `media_handle_sideload()`.

Sideloading was not chosen out of fondness for the back end: fetching the
image with `fetch` in the browser would run straight into CORS, and other
sites almost never send an `Access-Control-Allow-Origin` header. The server
is not bound by it. Core guards the endpoint against SSRF through
`wp_http_validate_url()`, which admits public http(s) only and rejects
private and local addresses.

### Several at once

Two or three images can be dropped one after another without waiting, and
several files can be dropped in one go. Each finished image is placed once:
the first of a drop takes the cell it landed on, replacing what was there,
and the rest take the empty cells to the right of it. Nothing is rearranged
and the search does not wrap round. What does not fit stays in the media
library, and a snackbar says so rather than leaving you to wonder.

Two things make that safe, and both are about time rather than about images.

**No callback carries data.** The upload ends long after the render that
started it, so a handler holding the cells as they were on the drop would
write back a row from before its neighbours arrived — and with two uploads
racing, whichever finished last would erase the other. Every write therefore
reads the attributes from the store at the moment it writes, through
`useBlockAttributes()`. That works because `updateBlockAttributes` is a plain
action object: the store has already changed by the time the previous write
returns.

The same hook dispatches rather than calling `setAttributes`, which core
fans out to *every* selected block — right for a click on a control, wrong
for an upload finishing on its own.

**An image's place in a drop is counted, not indexed.** Core reports the
whole batch again after each file of it, and the two uploaders in the field
disagree about the shape: the classic one passes the accumulating array and
drops failed entries out of it, the newer client-side pipeline passes one
attachment per call. Counting deliveries (`upload-batch.js`) is right under
both; reading the array index is right under neither.

The URL is read with `getAttribute( 'src' )` rather than the `.src` property:
the property would resolve a relative address against the editor's own URL
and send the request to our own site instead of the remote one.

What not to expect:

- What gets dragged is the file the other page happens to use in its markup.
  If that is a thumbnail, a thumbnail is what lands in the media library, not
  the original: the original's URL simply is not in `dataTransfer`.
- A remote server may answer 403 to a request without a `Referer`. The error
  text appears in the cell itself and is dismissed with a click.

For single images `core/image` does the same on its own: select an external
image and press "Upload to Media Library" in the toolbar, or upload them all
at once from the "External media" panel before publishing.

## Framing in the narrow strip

Two controls work as a pair: the **focal point** picks the spot, the
**scale** picks how close. What the crosshair covers ends up in the middle of
the strip.

The image element is sized at `scale` times the strip and filled with
`object-fit: cover`, so zooming is pure geometry. The element is then offset
until the focal point lands in the middle of the strip, clamped so no edge of
the image is ever dragged inside it:

```
left = clamp( W * (1 - S),  W * (0.5 - X * S),  0 )
top  = clamp( H * (1 - S),  H * (0.5 - Y * S),  0 )
```

Every term is the strip's own width and height, so the image's intrinsic size
never enters into it and the editor and the front end cannot drift apart. At
1× both offsets clamp to 0 and framing falls back to plain `object-position`.

This replaced a `transform: scale()` around the focal point, which was wrong
in a way that only showed at high zoom: that transform keeps the focal point
wherever it already sat in the strip. Aim at 83% down a 97 px strip at 4× and
the subject stays 83% down, 16 px from the bottom edge, while the middle of
the strip shows the part of the photo that sits about 8% higher. The crosshair
and the result disagreed by a third of the frame.

### Where the crosshair may go

The handle is kept inside the bounds it can actually honour, by
`src/shared/framing-picker.js`, which wraps core's `FocalPointPicker` for
both blocks. Two insets apply and the stricter one wins on each axis:

- half the handle, measured against the preview through a `ResizeObserver`,
  so the ring always stops with its edge against the picture;
- half the visible frame, `0.5 / scale`, so what the crosshair covers really
  can sit in the middle of the strip.

Without the second inset the crosshair lies near the edges: aim at 90% down
at 4× and the render clamps at the bottom of the image, leaving the subject
at 60% of the frame instead of in the middle. At 4× the reachable band is
12.5%–87.5%; at 2.6× it is 19%–81%. Positions outside it were never
reachable — the render clamped them anyway — so nothing is lost by refusing
them at the picker.

In an image row there is no zoom, `scale` stays 1 and only the handle inset
applies — and it does not apply to the anchor grid, see below.

Nothing already stored is rewritten. A focal point saved before this keeps
its framing until the handle is moved, and snaps into range only then.

## Framing in a column

Above the picker sits a three-by-three grid of anchors
(`AlignmentMatrixControl`). Its nine squares set the focal point to exactly
`0`, `0.5` or `1` on each axis, which is what "put the left edge of the
picture against the left edge of the cell" means. The handle inset is
cosmetic — it only keeps the ring from hanging outside the preview — so the
grid skips it; the frame inset, which is about what the render can reach at
all, still applies.

### Why the framing needed a second source

A column crop is hard and comes out at exactly the size of the cell. A
picture that already fits has nothing left to shift, so `object-position`
does nothing and the framing set in the editor was silently ignored on the
front end, while the editor preview — which works from the full-size URL —
obeyed it. WordPress cuts such a crop from the centre, so an important detail
near an edge simply disappeared, and nothing in the interface could move it.

A cell framed away from the centre is therefore rendered from `wow-band-src`
instead — the whole picture at the width of a row, the same soft source the
narrow strip uses — and the browser does the cropping, where
`object-position` does apply. Cropping to cover gives the same result either
way: a centred cell rendered from the whole picture is indistinguishable from
the hard crop, so nothing moves in a row left alone, and only a re-framed
cell downloads the larger file.

How far a pin can move the picture is however much of it hangs outside the
cell, and that depends on the source: a 754×350 photo in a 240 px column has
514 px to slide, a 250×350 photo has 10. For the second there is nothing to
be done — the pixels are not there. With `object-fit: cover` only one axis
ever overflows, so pinning top or bottom does nothing to a picture that is
proportionally wider than the cell, and pinning left or right does nothing to
one that is taller.

At 1× the strip shows the full width of the image; that is what
`object-fit: cover` does, shrinking the image to the smallest size that still
covers the strip. A focal point alone cannot single out a small detail:
without scale it only pans.

The upper bound is 4×. Beyond that much depends on the source: a large image
is served through the `wow-band-src` crop at 754 wide and has pixels to
spare, while an image no wider than the column itself is already stretched
and soft at 4×.

Scale exists only on the narrow strip. In the columns of a regular row,
framing is the focal point alone.

## The album grid on the front page

The `wow/album-grid` block lays out album tiles: cover, title and image
count. The first cell holds the site logo.

A tile has a **fixed width and never stretches**. A stretched tile on a wide
screen would show its cover larger than the crop and blur it. So a tile that
does not fit wraps to the next line and the grid stays centred:
`repeat(auto-fit, <fixed>)` plus `justify-content: center`. `auto-fit` rather
than `auto-fill`, because the latter leaves empty tracks and the centring
would drift along with the emptiness. The gap is the same `--wow-gap` used
inside the albums.

| Setting | Default |
| --- | --- |
| Tile width | 240 px |
| Columns on a wide screen | 5 |
| Badge colour | `#da8a13` |
| Number of albums | all |

The whole tile is a link to the album page.

Until a site logo is set, its cell stays reserved and reads "Upload a site
logo": otherwise the whole grid would shift by one tile the moment a logo
appeared.

### The image count

Images inside `wow/media-row` blocks count, as do `core/image` and gallery
images. **The narrow divider strip does not count at all**, its own image
included: it splits the gallery into parts rather than showing work. Solid
colour fills in columns are not images either.

The value is cached in the `_wow_album_image_count` post meta and recounted
when the album is saved: parsing every album's `post_content` on every
front-page view would be expensive.

The album post type lives in a separate plugin, `wow-albums`. The two are not
hard-wired: the post type key passes through the `wow_blocks_album_post_type`
filter.

## The album list on the album page

The `wow/album-list` block links to every album in the same manual order the
grid uses (`menu_order`). The current album is marked with an `is-current`
class and `aria-current="page"`.

The list floats through `position: sticky`: it sits in a narrow column beside
the gallery and travels with the page until the column runs out. The offset
from the top is configurable and defaults to 50 px, as on the client's
previous site.

Below 768 px the columns stack and the list would end up on top of the
gallery, so there it hides. This can be turned off with the "Hide on narrow
screens" setting.

The current album comes from `get_queried_object_id()` rather than from the
global `$post`: the block sits in the template next to the content, and
inside somebody else's loop the global post would wander off.

Colour and font size are handed to the editor through `supports`; the block
imposes no palette of its own.

## Vertical rhythm

The space between rows is set with `margin-block-start` on a doubled class
(`.wow-row.wp-block-wow-media-row`). A single class is not enough: for a
container core prints `> * { margin-block-end: 0 }` and
`> * + * { margin-block-start: <theme blockGap> }` at the same specificity,
and on rule order core won, leaving the theme's vertical gap rather than ours.

## Rendering

The blocks are dynamic: `save()` returns `null` and `render.php` assembles
the markup. The markup can change at any time without "block validation
failed" ever appearing, and the `srcset` is computed on the fly.

## Translations

Source strings are English. Translations live in `languages/`, one `.po` and
`.mo` pair per locale, plus a `.json` per locale for the editor scripts,
which is what `wp_set_script_translations()` reads.

```bash
# refresh the template after changing any string
wp i18n make-pot . languages/wow-blocks.pot --domain=wow-blocks

# after editing a .po
wp i18n make-mo languages
wp i18n make-json languages --no-purge
```

`make-json` must be re-run after every rebuild: the JSON file names carry a
hash of the script path, and a renamed bundle leaves the editor without its
translations.

## Development

```bash
npm install
npm run build     # build
npm run start     # rebuild on the fly
npm run lint:js
npm run lint:css
```

`wp-content` is mounted into the container, so a rebuild shows up on the site
straight away.

## Settled

- **No click, no zoom.** Images are not clickable and no lightbox is wired
  up. So a cell carries neither a link nor handlers, and the blocks need no
  `viewScript`: on the front end they stay pure markup with no JavaScript.
- **No caption.** A cell is an image or a fill, with no `figure`/`figcaption`
  and no text fields.
- The gap inside the narrow strip is 0; image and fill meet edge to edge, per
  the design.

The feature set of these blocks is closed.
