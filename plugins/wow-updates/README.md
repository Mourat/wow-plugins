# WOW Updates

Lets the plugins written for this site update themselves from the repository
they are kept in, the same way a plugin from the directory does: a line on
the Plugins screen, a button, and — where automatic updates are on — no
button at all.

## How it is wired

Nothing here is invented. WordPress has had a way to do this since 5.8, and
it is two things:

- a plugin names where it comes from in its header,
  `Update URI: https://github.com/Mourat/wow-plugins`;
- whoever understands that address answers the filter named after its
  hostname, `update_plugins_github.com`.

Everything after the answer is WordPress's own: comparing versions,
downloading, unpacking, the Plugins screen, the automatic updates. There is
no second updater running beside the first, which is the whole reason for
doing it this way rather than with one of the libraries that exist for it.

## One file for all of them

The answer comes out of a single file published with each release,
`plugins.json`, listing every plugin and its version. One request serves
eleven plugins, which matters: GitHub counts requests, sixty an hour from an
address, shared with everything else on that server.

It is fetched from the release rather than through the API, at an address
GitHub keeps pointing at the newest one:

    https://github.com/Mourat/wow-plugins/releases/latest/download/plugins.json

That costs no API allowance and needs no token while the repository is
public. The answer is kept for twelve hours — WordPress looks for plugin
updates twice a day, so anything shorter is spent on nothing — and a failure
is kept for one, so a repository that is down is not hammered.

## What is checked

The file is ours, but it arrives over the network and ends with WordPress
downloading and unpacking whatever address it names. So every field is read
rather than trusted, and a package may only be fetched from `github.com` or
`objects.githubusercontent.com`. A manifest naming anywhere else is dropped
entirely.

The filter also fires for plugins from other people's GitHub repositories,
so the `Update URI` is compared against ours before anything is answered.

## Commands

    wp wow-updates list              what the repository has, beside what is installed
    wp wow-updates list --refresh    ask again rather than use the kept answer
    wp wow-updates forget            throw the kept answer away

## Making a release

In the repository, not here: tag a commit `v0.2.0` and push the tag. The
workflow builds every plugin that needs building, zips them all, writes
`plugins.json` and publishes the release. Each plugin carries its own version
in its header, and that is the version this reads — the tag is only a name
for the release.
