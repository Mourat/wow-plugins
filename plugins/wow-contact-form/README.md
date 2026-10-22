# WOW Contact Form

A contact form as a block, built on nothing but WordPress itself. Every
message is filed in the site's own database and passed on by email; no
third-party service ever sees it.

Requires WordPress 7.0 — see *No JavaScript* below for why.

## How it behaves

| What                 | How                                                                                                   |
| -------------------- | ----------------------------------------------------------------------------------------------------- |
| The block            | `wow/contact-form`, in the WOW category of the inserter. One per page.                                |
| The fields           | First name, last name, company (optional), email, message.                                            |
| Sending              | A plain HTML form posting to its own page. No JavaScript is loaded, on the front end or in the editor. |
| After a good message | The visitor is sent back to the page with a thank-you in place of the form (303 redirect).            |
| After a bad one      | The page comes back with the problem named and everything they typed still in the fields.             |
| Where messages go    | **Messages** in the admin menu, readable only by administrators. Then by email to the site's address.  |
| If email fails       | The message is still there. The list shows *Not sent* with the reason.                                |
| Against robots       | A signed timestamp, an invisible field, the site's disallowed-words list, and one message a minute.   |
| The page             | A ready-made pattern, *Contact page*, under the Contact category of the pattern inserter.             |

## Decisions worth knowing

**The database is the delivery channel, not a backup.** Mail is the part of
any contact form most likely to fail quietly — a host with no mail transport,
a spam filter, an expired relay. A message that only ever existed in a failed
email is gone. Stored first, it is never gone, and the email becomes what it
should be: a notification. The visitor's "thank you" depends on the record
being saved, never on the mail going out.

**No JavaScript.** WordPress 7.0's `autoRegister` block support lets core
register a server-rendered block in the editor by itself, with a preview it
disables so the form cannot be typed into on the canvas. That is exactly the
editor experience this block wants, so there is no script to build, load or
translate. The flag is marked unstable in core; should it go, the fallback is
a twenty-line editor script and nothing else changes.

**The form posts to its own page, not to admin-post.php.** That file defines
`WP_ADMIN` and runs the whole admin bootstrap for every visitor's submission,
only to leave us hunting for the page to return to. Handling the request on
`template_redirect` keeps the page in hand. Where to return is read from a
hidden post ID, turned into an address by WordPress — never from the referer,
which is sender-supplied and often absent.

**The WordPress nonce is a freshness check, not a defence.** For a logged-out
visitor it has no user and no session to mix in, so every visitor gets the
same value and a robot simply reads it off the page. It proves the form was
drawn by this site in the last day, and a failure is reported as an expired
page, with the visitor's text handed back. The real token is a timestamp
signed with the site's salts: too fast is a robot, too old is stale.

**Robots are told nothing.** A submission that fills the invisible field or
trips the disallowed list is answered exactly as a good one would be, and
discarded. An error message would be a list of rules to work around.

**From stays the site's address.** Putting the visitor's address there is the
obvious move and the wrong one: the mail would claim to come from a domain our
server has no right to send for, and SPF or DMARC would see to it that nothing
arrived. The visitor is in `Reply-To`, where "reply" finds them anyway.

**Read-only in the admin.** Messages cannot be created by hand, quick-edited,
or opened in an editor — a record of what somebody said is not improved by
editing. The text is shown escaped and never passed through `the_content`,
where a `[gallery]` in a visitor's message would run.

**Plain text, and slashed.** Everything is reduced to text before storage, so
kses has nothing to rewrite — a visitor writing `if a < b` keeps their
sentence. And it is handed to WordPress slashed, as WordPress expects,
so backslashes and apostrophes come back out exactly as they went in.

## Adjusting it

Everything is a filter. There is no settings screen.

```php
// Send the notifications somewhere other than the admin email.
add_filter( 'wow_contact_form_recipient', fn () => 'contact@example.com' );

// A different subject line.
add_filter( 'wow_contact_form_subject', fn ( string $subject, array $values ) =>
	sprintf( 'Website: %s', $values['last_name'] ), 10, 2 );

// Rewrite the body; $values holds the cleaned fields, $post_id the record.
add_filter( 'wow_contact_form_body', fn ( string $body, array $values, int $post_id ) => $body, 10, 3 );

// Add, remove or rename fields. Same shape as includes/fields.php.
add_filter( 'wow_contact_form_fields', function ( array $fields ) {
	unset( $fields['company'] );
	return $fields;
} );

// How long a visitor must spend on the form (default 3 seconds).
add_filter( 'wow_contact_form_min_seconds', fn () => 5 );

// How long a drawn form stays valid (default 2 hours).
add_filter( 'wow_contact_form_max_seconds', fn () => DAY_IN_SECONDS );

// Quiet period between two messages from one address (default 60 seconds; 0 disables).
add_filter( 'wow_contact_form_throttle', fn () => 5 * MINUTE_IN_SECONDS );

// Do something with a message once it has been filed.
add_action( 'wow_contact_form_stored', function ( int $post_id, array $values ) { /* … */ }, 10, 2 );
```

Blocked words, addresses and IPs are the ones in **Settings → Discussion →
Disallowed Comment Keys**. The form uses the same list as the comments do.

## Mail on this server

The plugin sends through `wp_mail()`, which is only as good as the server
behind it. On a host with no mail transport every message shows *Not sent* in
the list, with the reason — the message itself is safe. Point `wp_mail()` at
a real SMTP server with `phpmailer_init` or a sending plugin, and the same
messages go out.

## Translations

The source is English. `languages/` holds the template and the French
catalogue, including the field labels as the site addresses its visitors
(*Votre Prénom*, *Votre Nom*, …). To refresh after a change:

```bash
wp i18n make-pot wp-content/plugins/wow-contact-form wp-content/plugins/wow-contact-form/languages/wow-contact-form.pot
wp i18n update-po wp-content/plugins/wow-contact-form/languages/wow-contact-form.pot
wp i18n make-mo wp-content/plugins/wow-contact-form/languages
```
