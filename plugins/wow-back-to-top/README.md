# WOW Back To Top

A round button in the bottom right corner of the front end that takes the
visitor back to the top of the page.

## How it behaves

| | |
| --- | --- |
| Where | Fixed in the bottom corner, 44 × 44 px, follows the text direction |
| Appears | After 300 px of scrolling, fading in |
| Press | Scrolls smoothly to the top |
| Without JavaScript | Never appears |

The colour matches the header and the footer (`#293641`).

## Decisions worth knowing

**A button, not a link to `#top`.** Pressing it scrolls the page the visitor
is already on; it does not navigate anywhere. A link would leave a stray
entry in the browser history, and Back would then scroll the page instead of
returning to the previous one.

**Hidden in CSS, revealed by the script.** If JavaScript does not run,
nothing floats over the page that would do nothing when pressed.

**`visibility`, not only `opacity`.** A button that is invisible but still
catches the Tab key is worse than no button at all.

**Focus is handled differently for a pointer and for the keyboard.** After a
click or a tap focus is simply let go of: the button hides itself, and moving
focus to the top of the page would draw the theme's focus ring around the
whole content area, shown to nobody who asked for it. After Enter or Space
focus does move to the top — otherwise it would be left on a hidden element
and fall back to the very end of the document, the opposite of what was just
asked for — and the ring is suppressed there too, because the next Tab lands
on the first link of the page and shows a ring where it belongs.

**Smooth scrolling is dropped for anyone who asked for less motion**
(`prefers-reduced-motion`), as is the fade.

## Adjusting it

Two filters, no settings screen:

```php
// Hide the button on the front page.
add_filter( 'wow_back_to_top_enabled', function ( $enabled ) {
	return is_front_page() ? false : $enabled;
} );

// Show it sooner.
add_filter( 'wow_back_to_top_offset', fn() => 150 );
```

The colours are CSS variables, so a theme can override them without touching
the plugin:

```css
:root {
	--wow-back-to-top-bg: #293641;
	--wow-back-to-top-bg-hover: #3a4d5c;
}
```

## Translations

The source is English; `languages/` holds the French translation. The only
string is the button's label.
