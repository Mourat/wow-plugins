# WOW Images

Keeps every picture on the site in a format that is worth sending, and offers
the browser a better one where there is one.

Two separate things, which can be used together or apart:

**Storing.** An uploaded JPEG is written out as WebP and the JPEG is not kept.
Every crop WordPress cuts afterwards comes out of the WebP, so the whole
library is one format and the uploads folder is smaller than it was.

**Copying.** Beside each file — the picture and all its crops — an AVIF is
written. Nothing is replaced; the AVIF sits next to its original and is only
sent to a browser that says it can read one.

On the page the two meet in a `<picture>`: an AVIF `<source>` first, then the
`<img>` exactly as WordPress built it. The browser takes the first it
understands. Nothing is negotiated on the server, no rewrite rule is needed,
and a cache in front of the site stores one copy of the page rather than one
per visitor.

## Why these two formats, and in this order

Measured on this site's own photographs, with both encoders set through
WordPress:

| | of the JPEG it came from |
|---|---|
| WebP q82, full-size pictures | 59% |
| WebP q82, crops | 74% |
| AVIF q55, full-size pictures | 40% |
| AVIF q55, crops | 50% |

The two quality scales are not comparable — they belong to different encoders
— and neither number means anything on somebody else's photographs. The
settings screen has both.

The stored picture has a quality of its own, and the tempting move — set it
higher than the crops, since it is the master everything else is cut from —
turned out to be wrong here. A copy that comes out larger than the picture it
was made from is thrown away, and that picture then gains nothing:

| stored at | of the originals' weight | left as JPEG |
|---|---|---|
| WebP q78 | 50% | 0% |
| WebP q82 | 57% | 4% |
| WebP q86 | 65% | 22% |
| WebP q90 | 73% | 55% |

Hence 82, the same as the crops. Raise it after measuring, not on principle.

AVIF is not offered as the *stored* format, only as a copy. A few browsers
still cannot read one, and a picture kept only as AVIF would show them
nothing at all. WebP is safe to store: every browser in use has read it since
2020.

## Double-density sizes

A theme that means to look right on a phone registers each crop twice — the
size it occupies on the page, and the same shape at double the pixels. The
browser takes whichever suits the screen, and on almost any phone made in the
last decade that is the larger one. So the 2x file is what most visitors
actually download, and it weighs two to three times the other.

It is also the one that can afford to be compressed hardest. A 2x file is
never drawn at its own size: four of its pixels are averaged into every pixel
the eye receives, and an encoder's artefacts live precisely in the detail that
averaging destroys. Each format therefore has a second quality, used only for
these sizes. On this site's photographs, at WebP 70 and AVIF 45:

| | before | after |
|---|---|---|
| a 1508×700 crop, WebP | 270 KB | 193 KB |
| the same, AVIF | 185 KB | 120 KB |

**How it is done, and why it is not simply a setting.** WordPress decides the
encoding quality once, when it opens the file, and the path it takes to cut a
hard crop never revisits that decision — which is why the `wp_editor_set_quality`
filter cannot tell one crop from another. The same fact is what makes this
possible: a quality set before the cut survives it. So `includes/retina.php`
cuts the 2x sizes again out of the same master WordPress used, at the quality
they deserve. One generation from the master, exactly as before. Nothing is
compressed twice.

The one case where the filter does get told the dimensions is a size resized
rather than cropped, and that is handled there too.

## Pictures that stay as they are

Not every picture gains. A JPEG saved at a low quality has already thrown away
its detail and put its own artefacts in its place — blocking along the 8×8
grid, ringing around every edge — and artefacts are the most expensive thing
an encoder can be asked to reproduce faithfully. Ask WebP for quality 82 and
it will spend bytes preserving the damage. Such a picture is left exactly as
it is, and the fact that it was weighed is written down so that nothing weighs
it again.

The common source of these is a photograph sent through a messaging app as a
*photo* rather than as a *file*: WhatsApp and the rest re-compress to about
quality 70 and cap the dimensions. One such upload measured here, 1500×1125 at
quality 70: WebP 82 came out at 120% of the JPEG and was rejected, while AVIF
at 55 came out at 82% and was kept. So those pictures still reach visitors a
third lighter — they simply stay JPEG on disk.

Worth telling whoever supplies the photographs: sent as a file, the same
picture arrives untouched, WebP beats it comfortably, and there is a real
master to cut future crops from rather than a copy of a copy.

## What it does not do

It does not compress the pictures in place, and it does not strip metadata.
WordPress already strips metadata from every crop it makes, and squeezing an
already-squeezed JPEG a second time costs quality for very little weight.

## The screens

**Settings → Image formats.** What this server can write, how far the library
has got, and every setting.

**Tools → Convert images.** Starts a job and watches it. The job runs on the
server: closing the tab, losing the connection or shutting the laptop changes
nothing, because the work is not happening here. See below.

**Media library.** A column saying how much each picture gained, and a bulk
action that hands a selection to the screen above.

## Commands

    wp wow-images status                 what is done and what is not
    wp wow-images convert                store and copy everything outstanding
    wp wow-images convert --limit=20     a batch at a time
    wp wow-images convert --force        make every copy again
    wp wow-images store --dry-run        what storing would change
    wp wow-images clean --dry-run        copies nothing asks for any more
    wp wow-images repoint --dry-run      point every page at the files as they are
    wp wow-images forget <id>            drop one picture's copies

`repoint` is run by `convert` and `store` when they finish, so it is rarely
needed by hand. It is the thing to reach for after a run that was interrupted,
after an import, or any time a page seems to be asking for a file that is not
there. It remembers nothing — every address is worked out afresh from the
attachments as they stand — so it can be run at any time and as often as you
like, and a half-finished conversion is put right by running it again rather
than by anything written down in advance.

That is also why a conversion no longer corrects pages one picture at a time.
It used to, and on this library that meant a search of every post for each of
a thousand pictures, times the dozens of revisions this site keeps per page:
eighteen minutes of database writes where one pass takes half a second — and
it was the worse of the two at the job, because it searched for the name each
picture has now, while a block saved before the picture was last cropped names
it as it was called then.

## How the job survives you

Converting a library takes minutes, and a browser is the wrong thing to hang
that on. So starting a job writes down what is to be done, and a worker takes
pictures off that list until it is close to whatever time limit PHP is under,
writes down where it got to, and asks the site to start another worker. The
screen only reads the tally.

Three things can set a worker going, because any one of them can be missing on
somebody's hosting:

- the worker itself, calling the site back when it stops;
- WP-Cron, as a watchdog, when a job has gone quiet with work left;
- the screen, which does a short turn of the work itself if it notices that
  neither of the above has happened.

The third is why this needs no configuring. A host that blocks requests from a
site to itself falls back to the browser driving, exactly as the old screen
did — and says so on the page, because then the tab does matter again. The
containers this site is developed in are such a host: the site's address is
`localhost:8080`, which exists only outside the container, so nothing inside
it can reach the site at all. Convert locally with WP-CLI instead, where there
are no limits to work around.

A worker arrives with no session — the request that starts it carries no
cookies — so it proves itself with a secret kept in an option rather than with
a nonce. Only one worker runs at a time, which is enforced by an INSERT that
cannot succeed twice; a lock older than ninety seconds is assumed to belong to
a worker that died and is taken from it.

## Two things worth knowing before a mass conversion

**Storing cannot be undone.** The picture it was made from is deleted. Take a
copy of `wp-content/uploads` and of the database first — the two together,
because what a picture remembers about its copies lives in the database, and
files without it are files nobody will offer.

**Check which image library is in use.** The settings screen says. In this
project the `wpcli` container has an Imagick built without any delegates at
all, so WordPress falls back to GD there and writes different files; the site
container and the live host both use ImageMagick. Convert through the screen
in Tools, or through the site container — not through `wpcli`.

## Where things are

| | |
|---|---|
| `includes/formats.php` | the formats, and what this server can write |
| `includes/options.php` | the settings |
| `includes/record.php` | what an attachment remembers about its copies |
| `includes/convert.php` | making the copies |
| `includes/store.php` | replacing the picture itself, on upload and after |
| `includes/retina.php` | the double-density sizes, cut again at their own quality |
| `includes/references.php` | pointing the pages at the pictures as they are now |
| `includes/output.php` | the `<picture>` on the page |
| `includes/queue.php` | what is left to do |
| `includes/cleanup.php` | taking the copies away again |
| `includes/admin.php` | settings screen, media column, bulk action |
| `includes/job.php` | the job, the worker, the lock and the watchdog |
| `includes/bulk.php` | the screen that starts a job and watches it |
| `includes/cli.php` | the commands |
