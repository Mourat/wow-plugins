# WOW Mail

Sends the site's mail through a real mail server, and says plainly which of
the things that decide whether it lands in the inbox are actually in place.

## The thing to understand first

An SMTP plugin does not stop mail going to the spam folder. It is worth being
blunt about that, because every plugin of this kind is sold as if it did.

Three separate questions decide where a message ends up, and only the first
is in WordPress:

1. **Did it leave at all?** By default WordPress hands messages to PHP's
   `mail()`, which gives them to whatever the web server has — unauthenticated,
   from an address nobody vouches for. On a lot of hosting that fails outright;
   on the rest it arrives looking like something nobody vouched for. **This is
   what the plugin fixes.**

2. **Is the sender real?** The From address has to be a mailbox that exists,
   at the same domain the mail server authenticates us for. WordPress sends as
   `wordpress@` the site's domain, which is usually nothing at all. **This is
   also what the plugin fixes.**

3. **Does the receiving end believe it?** Decided by three records in the
   domain's DNS, which belong to whoever holds the domain and cannot be put
   there by any plugin. **This the plugin only reports on** — but it does
   report, in plain terms, so nobody has to guess.

| | what it is | where it is set |
|---|---|---|
| **SPF** | a list of servers allowed to send as this domain | DNS, one TXT record |
| **DKIM** | a signature on each message | at the mail provider, which publishes the key |
| **DMARC** | what to do when the first two do not match | DNS, one TXT record |

Without SPF and DMARC, a perfectly configured site still lands in the spam
folder. The settings screen says whether they are there, having actually asked
DNS rather than assuming.

DKIM cannot be checked from here: the provider publishes the key under a name
it chooses, and there is no way to look for a name you have not been told. Ask
the provider whether it signs outgoing mail; most do by default.

## The password

It has to be somewhere, and the two honest places are not equal.

**A constant in a file** is the better one and this plugin prefers it:

    define( 'WOW_MAIL_PASSWORD', '…' );

Either in `wp-config.php`, or in a must-use plugin — which is where this site
keeps it, `wp-content/mu-plugins/wow-secrets.php`, because that folder travels
with the project and works the same on every machine, while wp-config.php on a
containerised development site lives somewhere else entirely.

Either way the password is in a file, outside the database, and not in any of
the dumps that get taken nightly, emailed around and left in folders.

A file in `wp-content` is inside the web root, so it is worth putting an
`.htaccess` beside it refusing direct requests. Nothing ever asks for a
must-use plugin by address; the rule is there for the day the server stops
executing PHP and starts serving it as text.

**Failing that** it goes in the options table, encrypted with a key made from
the site's own salts — which live in wp-config.php and not in the database.
That is a real distinction and worth being precise about: it protects a
database that leaks on its own, which is how these usually get out. It
protects nothing at all against somebody already on the server who can read
wp-config.php; at that point they have the database password too.

## When mail will not go

The test send turns PHPMailer's own reporting on and keeps the whole exchange
— which greeting failed, which password was refused, which certificate was not
trusted. The one-sentence failure WordPress normally gives is rarely the
reason; the dialogue nearly always is.

    wp wow-mail test somebody@example.com

## Commands

    wp wow-mail status                   how it sends, and what DNS says
    wp wow-mail test <address>           send one, and print the exchange

## Where things are

| | |
|---|---|
| `includes/options.php` | the settings |
| `includes/secret.php` | keeping the password out of a database backup |
| `includes/mailer.php` | pointing PHPMailer at the mail server, and the test send |
| `includes/sender.php` | who the mail says it is from |
| `includes/dns.php` | asking DNS what the domain says about itself |
| `includes/log.php` | a fortnight of attempts, never the messages |
| `includes/admin.php` | the settings screen |
| `includes/cli.php` | the commands |
