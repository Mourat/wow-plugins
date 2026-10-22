# WOW Albums

The **Albums** post type: catalogues of images.

## What it provides

| | |
| --- | --- |
| Post type key | `wow_album` |
| Archive | `/albums/` |
| Single post | `/albums/<slug>/` |
| Editor | Gutenberg (`show_in_rest`) |
| Supports | title, editor, featured image, excerpt, revisions, page attributes |

The album list gets a **Cover** column (the featured image) right after the
checkbox, plus a drag handle.

## Album order

The order is manual: drag a row by the handle on the left and it is written
to `menu_order` over AJAX straight away.

Dragging works **only in the full list**: no search, no status or date
filter, no column sorting. The order is derived from row positions, and in a
filtered list it would shuffle things that are not on screen. When dragging
is locked the handles are dimmed and their tooltip explains why.

Pagination is accounted for: on page two the order continues rather than
restarting at zero.

### Getting albums in the right order

The plugin supplies `menu_order` **only when no sort order is set
explicitly**:

```php
// manual order: orderby is not set
$albums = new WP_Query( [ 'post_type' => 'wow_album' ] );
```

`get_posts()` and the Query Loop block **always set** `orderby` themselves
(`date` by default), so there the manual order has to be asked for by name:

```php
$albums = get_posts( [
	'post_type'      => 'wow_album',
	'posts_per_page' => -1,
	'orderby'        => 'menu_order',
	'order'          => 'ASC',
] );
```

This is deliberate: silently overriding somebody else's sort order is a
source of bugs that are hard to pin down.

## Permissions

Reordering requires the `edit_others_posts` capability (editors and
administrators). Each post is additionally checked with `edit_post`.

## Layout

```
wow-albums.php             loading, activation, rewrite rule flushing
includes/post-type.php     post type registration
includes/admin-columns.php the cover and the handle in the list
includes/ordering.php      ordering: sorting, AJAX, permissions
assets/admin-order.js      row dragging
assets/admin.css           list styles
```

No build step: plain JS, no bundler.

## Translations

Source strings are English. Translations live in `languages/`, one `.po` and
`.mo` pair per locale.

```bash
# refresh the template after changing any string
wp i18n make-pot . languages/wow-albums.pot --domain=wow-albums

# after editing a .po
wp i18n make-mo languages
```

## Where the albums are displayed

Front-end output lives in the separate `wow-blocks` plugin: the
`wow/album-grid` block for the front page and `wow/album-list` for
navigation on an album page. The two plugins are not hard-wired together —
`wow-blocks` reads the post type key through the `wow_blocks_album_post_type`
filter.

## The albums in the navigation menu

The block **Album links** (`wow/album-links`) prints one menu item per album,
in the same manual order as everywhere else. It is meant to go inside a
navigation submenu, and the theme already places it there — see
`wow-theme/parts/header.html`.

It exists because core has nothing that lists a custom post type in a menu:
`core/page-list` is pages only, by way of `get_pages()`. Writing the links out
by hand would work today and be wrong by the next album.

It has two shapes, chosen with the `disclosure` attribute:

- **Plain** (default): bare `<li>` elements and no wrapper, because the
  submenu around it supplies the `<ul>`. Each item carries the album's cover;
  the theme lays them out as a wide panel of tiles. Dropped outside a submenu
  it produces list items with no list.
- **Disclosure** (`disclosure: true`, with a `label`): one `<li>` holding a
  native `<details>` — the label as the summary, the albums as a plain text
  list inside, collapsed until tapped. This is the mobile shape.

Why two, and not one that adapts: inside the navigation overlay core reports
every submenu as expanded — its `isSubmenuOpen` reads the overlay's own open
state — so a core submenu there can never be collapsed, whatever the styles
say. `<details>` collapses by itself, needs no script, and tells a screen
reader the truth. The theme shows the plain shape on wide screens and the
disclosure in the overlay; both sit side by side in `parts/header.html`.

The markup and class names are core's own navigation item markup, so the
navigation block styles the items as it styles a hand-placed link, and the
album being viewed gets `current-menu-item` and `aria-current="page"` the
same way.
