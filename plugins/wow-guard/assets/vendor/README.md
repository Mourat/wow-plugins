# qrcode.js

QR Code Generator for JavaScript, by Kazuhiko Arase. MIT licence, version
2.0.4, taken from the npm package `qrcode-generator` and not modified.

Here because the enrolment screen has to show a QR code, and the two
alternatives were worse: sending the secret to somebody else's server to be
drawn, or writing a QR encoder from scratch — several hundred lines of
Reed–Solomon and masking where a subtle mistake produces a code that some
scanners read and others do not.

Checked before being vendored: it contains no `eval`, no `Function`, no
network call of any kind, no storage, no cookies — and, tellingly, not one
reference to `document`, `window` or `globalThis`. It computes a matrix and
returns a string. The page does the rest.

Loaded only on the screen where somebody is setting up their second factor.
