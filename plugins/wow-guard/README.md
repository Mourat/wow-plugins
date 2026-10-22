# WOW Guard

The handful of things that actually keep a small WordPress site from being
broken into, and nothing else.

Most of what security plugins sell is either theatre or somebody else's
service. There is no malware scanner here, because one that worked would have
to know what every file on the site is supposed to contain. There is no
request firewall matching patterns against query strings, because it produces
false positives on a good day and is walked around in a minute on a bad one.
There is no country blocking. What is here is the short list of things that
are known to work, each of them small enough to read.

## What matters most, in order

**A limit on password guessing.** The only item on this list that stops an
attack rather than making it quieter. It hangs on `authenticate` rather than
on the login form, and that is the point: the login form is not the only way
in. XML-RPC authenticates through the same function and its `system.multicall`
can carry hundreds of guesses in one request — which is how sites with a
guarded login page still get broken into.

**Not saying who may log in.** Guessing a password takes two things and
WordPress gives one away: ask for `/?author=1` and it answers with the name.
This closes that, the same question asked of the REST API, the author archive
and the sitemap. With the name unknown, the guessing has two unknowns instead
of one.

**The same refusal either way.** WordPress distinguishes an unknown name from
a wrong password. To somebody working through a list of names that is the
answer to the only question worth asking, and it is free to stop telling them.

**Keeping up to date.** Most sites that are broken into are running a version
with a hole that was published and fixed months earlier. Security and
maintenance releases install themselves; a new major version waits to be
asked for, because it can change the editor a site is built on.

**Doors nobody here uses.** XML-RPC, application passwords, the file editor in
the admin. Each is open only because WordPress has to work for everybody.

**The uploads folder.** The one place the outside world can put files. If
anything that is not a picture ever lands there, an Apache rule is the
difference between a nuisance and a disaster. (Apache only — on nginx the
same thing belongs in the server configuration, and the settings screen says
so rather than pretending.)

**Headers.** `nosniff`, `SAMEORIGIN`, a referrer policy, and a refusal of
camera, microphone and location. They do not stop anybody getting in; they
limit what can be done to a visitor already looking. Deliberately absent: a
content security policy, which has to be written against the scripts a site
actually loads rather than switched on.

## Two-step login

The one thing here that makes guessing the password beside the point: knowing
it is no longer enough. Off by default and per person — nobody is given a
second factor by somebody else, because setting one up means holding the
thing that proves it.

Two ways, and one at a time rather than both:

**An authenticator application.** TOTP, from RFC 6238, which every one of
them speaks. The strong one: the secret never travels, so there is nothing to
intercept and nothing to phish but a code worth thirty seconds. The algorithm
is written out here rather than depended on — it is HMAC-SHA1 over the current
half-minute and about eighty lines — and it is checked against all five test
vectors in the RFC, which is how you know any application will agree with it.

**A code sent by email.** Weaker, and the screen says so rather than letting a
checkbox imply otherwise: email is usually also where a password reset goes,
so somebody holding the mailbox holds both factors. It is here because it
needs no application and no phone, and a second factor somebody actually uses
beats a better one they do not.

**Recovery codes** either way: ten of them, shown once, stored the way a
password is stored, each good for one entry. They are what stands between a
lost phone and a lost site. The screen that shows them offers them as a file
to keep — built into the page out of the codes already on it, so that nothing
has to hand them out a second time and no address exists that could be asked
to.

### Nothing is switched on unproved

Neither way can be switched on by saying so. A code comes first, it is typed
back, and only then does anything change — which is the practice everywhere
else and is not politeness. The code is the only evidence there is: for the
application, that it holds the secret and that the two clocks agree; for
email, that mail from this site reaches that mailbox at all. Switching it on
without it means finding out at the next login, locked out, that it does not.

A code that could not be sent is thrown away rather than waited for, so the
screen never asks for one that is not coming.

Switching it off asks for the same proof. A second factor that a borrowed,
still-signed-in browser can take off in one click was never a second factor,
and every other site asks. A recovery code counts as proof here too, and is
spent when it is used.

The one exception is an administrator on somebody else's profile, who may
switch it off and may not switch it on. That asymmetry is the whole point:
turning it off is the documented way back in when a phone is lost, and
turning it on would mean handing somebody a secret, which is no longer a
secret once handed over.

Five wrong codes and the emailed one is thrown away. Six digits are not many,
and nothing else on that page slows a guesser down. The count is kept for
exactly as long as the code had left, so getting it wrong cannot be used to
keep it alive.

### The order of events

WordPress hands out the cookie and announces the login afterwards, so by the
time any plugin hears about it the person is already inside. The cookie is
therefore taken straight back, and given again only once the code is right.
In between, the only thing tying the form to the person who got the password
right is a ticket: sixteen random bytes, kept on the server, good for ten
minutes and for one login.

No nonce on that form, which is deliberate and was learned the hard way. A
WordPress nonce is bound to whoever is logged in, and at that moment nobody
is — so one made while the form is drawn never matches when the form comes
back, and the second step refuses everybody with "the link you followed has
expired". The ticket does the same job and does not have that problem.

A code is accepted once. Without that, one glanced at over a shoulder is good
for its whole half-minute and the two either side, which is long enough for
somebody standing behind you. Five wrong codes and the half-finished login is
thrown away entirely.

### Remembering a browser

Under the code field there is a box: do not ask on this browser for thirty
days. Ticked, the second step is skipped on that machine until the month is
up; left alone, nothing is remembered. It exists because a factor paid ten
times a day on one's own desk is a factor that gets switched off, and a
second factor that is on four days a week beats a stricter one that lasted a
fortnight.

What it costs is worth saying in plain words, and the screen says it: for
those thirty days, on that browser, the password alone gets in. A cookie
lifted off that machine is as good as the second factor. So it is a choice
made each time rather than a default, and the box sits next to a line about
shared computers.

The cookie holds thirty-two random bytes and nothing else — no account, no
expiry, nothing to edit. What the database keeps is an HMAC of it under the
key from `wp-config.php`, the same arrangement the TOTP secret gets: a
database that leaks on its own gives up nothing that can be put back into a
browser. Ten browsers at most, the oldest dropped, each with a coarse label —
"Chrome on Windows" — so that the list on the profile page can be read and
recognised. The label is for the person reading it and nothing else: anybody
who can send a stolen cookie can send a user agent to match it.

It is checked after the password and before the cookie is taken away, so it
skips the second step and never the first.

Every remembered browser is forgotten when the second factor changes, when it
is switched off, and when the password changes — that last through
`wp_set_password`, which the reset link, the profile page and WP-CLI all end
up calling, so there is one listener rather than one per road in. The profile
page lists them and has a button to forget the lot.

A site that would rather pay the cost every time:

    add_filter( 'wow_guard_remember_browser', '__return_false' );

### The way back in

    wp wow-guard 2fa list
    wp wow-guard 2fa disable <user>

It needs a shell on the server rather than a password, which is the point: it
is for the day somebody loses both their phone and the paper with the recovery
codes on it.

### When none of it happens

All of it rides on WP-Cron, which fails quietly. WordPress runs what it has
scheduled by asking itself for `wp-cron.php` over HTTP when a visitor arrives,
and sends that request without waiting for an answer — so a site that cannot
reach itself looks exactly like a site with nothing to do. The switches above
go on meaning what they say and nothing acts on them.

This is not hypothetical. On the local container the site answers on port 80
while its address says 8080, which is a port on the machine outside; every
request it made to itself failed, and nothing scheduled ran for two days.

So the settings screen says which it is. Work piling up in the past is the
only visible symptom, and three hours of it is the line — WP-Cron has no clock
of its own, and a quiet morning means nothing. Past that the screen says how
far behind it is and, if something is overdue, knocks on the site's own door
once a quarter of an hour and repeats what came back. The key it knocks with
is deliberately wrong, so `wp-cron.php` answers and stops rather than starting
a run.

A site nobody visits has the same symptom and a different cause, and the
screen says that too. The cure there is a real cron on the server:

    */15 * * * * cd /path/to/site && wp cron event run --due-now

## Moving the login page

This one is not security, and the settings screen says so. Anybody who has
decided to break into this site in particular will find the page.

What it is good for is everything else: the machines that work through lists
of addresses trying `wp-login.php` on each one. They stop finding anything,
which means a quieter server, a readable log, and a lockout list that only
ever contains somebody who really did forget their password.

Two things make the difference between it working and being a nuisance. Every
address WordPress builds for itself has to move too — the form it posts to,
the link in a password-reset email, the little window that appears when a
session expires mid-edit. And `wp-admin` must stop redirecting: a logged-out
visitor asking for it is normally sent to the login page, which once moved
means being handed the new address by the site itself. So it answers "not
found" instead.

**The way back in**, for somebody who has forgotten where they put it:

    define( 'WOW_GUARD_LOGIN_SLUG', 'whatever-you-like' );   // in wp-config.php

or, with no line at all:

    wp wow-guard login-url --off

## Reading a visitor's address

The limit on guessing counts attempts per address, so where that address comes
from decides whether it works. Trust a forwarded header that nothing in front
of the site sets, and anybody can send a different one with every guess and
never be locked out. Refuse to read it when a proxy really is in front, and
every visitor shares the proxy's address, so the first person to mistype a
password locks out the world.

The address the web server saw is therefore the only one trusted by default.
Reading a header instead is a setting, to be turned on by somebody who knows
what sits in front of their site.

## Commands

    wp wow-guard status                      what is on, and what it has seen
    wp wow-guard login-url                   where the login page is
    wp wow-guard login-url --set=<slug>      move it
    wp wow-guard login-url --off             put it back
    wp wow-guard unlock                      lift every lockout
    wp wow-guard unlock 203.0.113.9          lift one

## What this cannot do for you

**The username.** A plugin can stop the site announcing it; it cannot change
what it is. An account called `admin` has already given away half the problem
to anybody who guesses the obvious.

**Backups.** Nothing here helps after the fact.

## Where things are

| | |
|---|---|
| `includes/options.php` | the settings |
| `includes/visitor.php` | which address a request came from |
| `includes/attempts.php` | the limit on password guessing |
| `includes/log.php` | a week of failed attempts, and no more |
| `includes/names.php` | keeping the list of users out of public view |
| `includes/doors.php` | XML-RPC, application passwords, the file editor, versions |
| `includes/updates.php` | what installs itself |
| `includes/headers.php` | what the browser is told |
| `includes/uploads.php` | the rule that stops anything there running |
| `includes/login-url.php` | moving the login page |
| `includes/totp.php` | the six digits, and the base32 they are kept in |
| `includes/two-factor.php` | the second step, both ways of proving it, the recovery codes |
| `includes/two-factor-ui.php` | setting it up, on one's own profile |
| `includes/trusted.php` | browsers that are not asked again for thirty days |
| `includes/secret.php` | keeping a secret out of a database backup |
| `assets/vendor/qrcode.js` | drawing the QR code — read before it was vendored, see its README |
| `includes/admin.php` | the settings screen |
| `includes/cli.php` | the commands, including the way back in |
