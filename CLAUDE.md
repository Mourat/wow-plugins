# Working in this repository

## Ask the graph before writing new code

A new function, a new feature, or any question of the form "how does WordPress
do this" starts with the knowledge graph, not with a guess and not with
grepping core by hand.

    ~/projects/wp-core/graphify-out/graph.json    WordPress core
    ~/projects/ArleaMH/graphify-out/              the site itself

The `graphify` command is not on PATH and the module does not import under the
system python3, so the graph is read straight out of the JSON: `nodes` carry
`id`, `label`, `source_file` and `source_location`, `links` carry `source`,
`target`, `relation` and `confidence`. A regex over `id` and `label` answers in
seconds and hands back the file and line to open next.

The graph of core was built by parsing **PHP**. JavaScript and CSS are not in
it, so "not in the graph" is not "not in core" — those still have to be read
from `wp-admin/js/` and `wp-admin/css/` on the running site.

It is worth the two minutes. Building the hover-to-open admin menu, the graph
said at once that whether the menu is folded is a *user setting* — `mfold`,
read in `admin-header.php` — and not an option, which is the difference
between a tweak that leaves the visitor's own preference alone and one that
overwrites it.

## The rest

`README.md` holds the release flow, how a site gets an update, and the one
plugin with a build step. Everything there is load-bearing; the version
header in particular.
