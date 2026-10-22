# WOW Map

A map of one place, as a block. The picture is drawn once on this server from
OpenStreetMap tiles and kept in `uploads`, so reading the page asks nothing of
anybody else.

Requires WordPress 7.0 — see *No JavaScript* below for why.

## How it behaves

| What                | How                                                                                                 |
| ------------------- | ----------------------------------------------------------------------------------------------------- |
| The block           | `wow/map`, in the WOW category of the inserter.                                                      |
| Settings            | Latitude, longitude, zoom, width, height and alt text, as ordinary fields in the block inspector.     |
| When it is drawn    | The moment somebody who can edit puts the block on a page. A visitor never triggers a download.       |
| Where it is kept    | `wp-content/uploads/wow-map/`, named after the coordinates, zoom and size.                            |
| On the page         | A plain `<img>` served from this site, plus the credit line underneath.                               |
| If it cannot be drawn | Editors see why. Visitors see nothing at all.                                                       |
| Deepest zoom        | 19. The standard OpenStreetMap layer has nothing below that.                                          |

## Decisions worth knowing

**The tile server answers a faceless request with a fake.** This is the single
most important thing in the plugin. Ask for a tile without a user agent that
identifies you and the reply is **HTTP 200 with a valid PNG** — except it is a
"blocked" placeholder rather than a map, and the only sign of it is an
`x-blocked` header. So success is never judged by the status code: a response
carrying that header is refused outright, with a message saying so, and
nothing is written to disk. The OpenStreetMap Foundation sets out what it
expects at https://operations.osmfoundation.org/policies/tiles/

**Drawn here, served from here.** Before this plugin, the site made no
third-party request at render time at all — no embeds, no CDN, no web fonts.
A live map in an `<iframe>`, or a JavaScript map fetching tiles, would send
every visitor's IP address to somebody else on every page view. Drawing the
picture once keeps that property, and for a map of a fixed address there is
nothing to be gained from panning it.

**No JavaScript.** WordPress 7.0's `autoRegister` block support lets core
register a server-rendered block in the editor by itself, and build the
inspector controls out of the block's own attributes. For a block whose
settings are five numbers and a caption, that is the entire editor interface,
written by nobody. The flag is marked unstable in core; if it goes, the
fallback is a twenty-line editor script and nothing else changes.

**The editor draws it, the front end never does.** The editor's preview is
rendered through `/wp/v2/block-renderer/wow/map`, which already requires the
permission to edit. So the only request that may go out to fetch tiles is one
made by somebody logged in and working on the page. A failure is remembered
for ten minutes and a lock prevents two simultaneous draws, so a tile server
having a bad day does not get hammered while somebody types.

**Not in the media library.** These pictures are generated from the block's
own settings, not files anybody chose. Filing them among several hundred real
attachments — which this site sorts into folders by hand — would be clutter,
and nobody would ever pick one from a list.

**The credit is not optional.** OpenStreetMap data is licensed under the ODbL,
which requires the credit wherever the map appears. It is the caption under
the picture, and the only link the block renders: the map itself is just a
picture.

**No @2x on the deepest zoom.** A retina picture is drawn from the next zoom
down, and at zoom 19 there is no next zoom — the server answers 400. At 18 and
above it simply does not apply; on a high-density screen the browser scales
the picture up.

## Adjusting it

```php
// How the plugin introduces itself to the tile server. The site URL goes in
// by default; add contact details here if you would rather they had them.
add_filter( 'wow_map_user_agent', fn () => 'in-folia/1.0 (+https://example.com; hello@example.com)' );

// The colour of the marker. Six hex digits, hash optional.
add_filter( 'wow_map_marker_colour', fn () => '#b3261e' );
```

## From the command line

```bash
# Draw every map the site actually uses. Safe to run repeatedly.
wp wow-map render

# Try one before putting the block anywhere.
wp wow-map render --lat=43.629528 --lon=6.958389 --zoom=17

# Draw again even where a picture already exists.
wp wow-map render --force

# See which pictures no block asks for any more, then remove them.
wp wow-map prune --dry-run
wp wow-map prune
```

`prune` earns its keep because changing a zoom level or nudging a coordinate
files the new picture under a new name and leaves the old one behind.

## Moving the site

`uploads/wow-map/` travels with the uploads folder like any other media. If it
is left behind, `wp wow-map render` draws everything again from the blocks
already on the pages.

## Translations

The source is English. `languages/` holds the template and the French
catalogue. Command-line output is not translated, matching the other plugins
here. To refresh after a change:

```bash
wp i18n make-pot wp-content/plugins/wow-map wp-content/plugins/wow-map/languages/wow-map.pot
wp i18n update-po wp-content/plugins/wow-map/languages/wow-map.pot
wp i18n make-mo wp-content/plugins/wow-map/languages
```
