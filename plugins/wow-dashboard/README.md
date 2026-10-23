# WOW Dashboard

Visual adjustments to the WordPress admin — how it looks and moves, never
what it does. One so far: the folded menu opens while the pointer is on it.

## How it behaves

| | |
| --- | --- |
| When | The menu is folded and the pointer can hover |
| Opens | After 280 ms on the menu, at the full 160 px |
| Closes | The moment the pointer leaves, with no pause |
| The page | Does not move: the menu is painted over it |
| Touch screens | Nothing happens; WordPress has its own menu there |

## Decisions worth knowing

**It floats instead of pushing.** `#wpcontent` keeps the 36 px gutter it was
laid out with, and the menu overhangs it with a shadow along the edge. A menu
that took the width for real would reflow the whole page for a glance at it,
and a table being re-laid out under the pointer is a worse answer than the
icons were.

**Opening waits, closing does not.** The pause is what separates looking at
the menu from crossing it on the way to somewhere else. Once the pointer has
left, there is nothing to weigh up: the menu is in the way, so it goes.

**A class on `<body>`, nothing else in the script.** `menu.js` starts a timer
and sets `wow-menu-peek`; everything visible is in `menu.css`. That is also
why the folded state is read at the moment of hovering rather than once at
load — the collapse button folds and unfolds the menu without a reload.

**The stylesheet undoes core rule by rule.** There is no way to unset a
stylesheet, so each line restores the value the menu has when it is open for
good, in the order core states them in `admin-menu.css`. The current
section's submenu has to come after the fly-out rule: it matches both, and
with identical weight the later one lands.

**Putting that submenu back inline also settles the script side.** Core hangs
`hoverIntent` on every item with a submenu, and it opens a fly-out and nudges
it up the window to fit. It bails out when the submenu's computed `top` says
it is already visible — which is exactly what an inline list says — so the
list stops being opened and shoved about as the pointer passes over it.

**Whether the menu is folded is not ours to change.** It is a user setting,
`mfold`, read in `admin-header.php`. The plugin reads the class it produces
and never writes the setting: the visitor's own preference survives.

## Filters

| | |
| --- | --- |
| `wow_dashboard_hover_menu` | `false` turns the whole thing off |
| `wow_dashboard_hover_wait` | the pause before it opens, in milliseconds |
