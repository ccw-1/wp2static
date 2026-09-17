# WP2Static

- Contributors: CCW-1
- Tags: static, export, cache
- Requires at least: 5.5
- Tested up to: 6.x
- Requires PHP: 7.4
- Stable tag: 0.3.0
- License: MIT

Crawls your WordPress site over HTTPS and exports a self-contained static
version to a directory. Pages become `*.html` files, assets are downloaded,
and forms keep POSTing to the live PHP endpoints so submissions still work.

## Description

WP2Static crawls the live site (so cached pages load fast), rewrites internal
links to local `.html` files, downloads self-hosted CSS/JS/images, and injects a
small client-side script on every exported page. It is designed to survive
shared-hosting watchdogs: the crawler runs in small resumable batches with
state persisted after every page.

Key behaviors:

- Entry URL is `home_url()/?wp2static=1`, so a `/` -> `/s/` web-redirect (for
  serving the static copy at the site root) does not break re-exports.
- Internal links become relative `.html` paths; excluded URLs
  (`wp-admin`, `wp-login`, `wp-json`, `admin-ajax`, feeds, `.php`, file
  extensions) are left absolute so they keep pointing at the live site.
- Forms with no/relative `action` are pointed at the live PHP endpoint.
- `wp2static.js` on exported pages fixes any remaining absolute self-hosted
  links and form actions at runtime (no-op on the origin host).
- AJAX endpoints injected by `wp_localize_script` (e.g. `admin-ajax.php`) are
  rewritten from absolute-home URLs to host-relative paths. This keeps an
  export served from a different host than the origin (e.g. `www` vs. bare) on
  the same origin for XHR; otherwise the browser CORS-blocks the call. Add
  plugin-specific AJAX paths (e.g. `/request-quote/` for YITH Request a Quote)
  in the "Host-relative path rewrites" setting.
- Transient HTTP 500/502/503/429 responses are retried up to 3 times so slow or
  flaky hosts do not silently drop pages.

## Installation

1. Upload the `wp2static` folder to `wp-content/plugins/` or install the zip
   via Plugins -> Add New.
2. Activate the plugin.
3. Go to Settings -> WP2Static.
4. Set the Output directory to an absolute filesystem path you serve statically
   (e.g. `/path/to/docroot/s`). The directory must exist or be creatable and
   writable by PHP.
5. Save, then "Run export now" and watch the log. Deeper/larger sites may
   finish instantly (one batch) or need several runs; the export resumes where
   it left off.

## Frequently Asked Questions

### How do I show the static copy at the site root while keeping wp-admin working?

Serve the export at a subdirectory (e.g. `/s/`) and add a rewrite in the web
root that redirects only the bare homepage:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{QUERY_STRING} ^$
RewriteRule ^$ https://%{HTTP_HOST}/s/ [R=301,L]
</IfModule>
```

The plugin's crawler uses `/?wp2static=1` (query string), so it is not
redirected and re-exports keep working. `wp-admin`, `wp-login.php`,
`admin-ajax` and search are not affected.

### The static copy is served from a different host (www vs bare) and a button does nothing.

That is normally a CORS block: the page is on `www.domain` but an AJAX URL in
the HTML points at `domain`. The crawler rewrites `admin-ajax` (and any paths
you list in "Host-relative path rewrites") to root-relative paths, which fixes
it on the next export. Serve the same export through your HTTP cache TTL before
testing, or append a cache-busting query string.

### Why do forms still point at the live site?

Because static HTML cannot process submissions. Requests/Ajax endpoints stay
on the live WordPress host (that is what keeps contact forms, quote lists and
carts functional). The static copy is a mirrored front-end, not an offline app.

## Changelog

### 0.3.0

- 0.3.0: new "Extra JavaScript" setting — JavaScript is appended to the
  exported `wp2static.js` on every page, so theme-specific behaviour (e.g.
  re-randomizing a Divi math captcha on the contact form) can be injected
  without forking the plugin; author + repo attribution.

### 0.2.0

- 0.2.0: generic packaging; YITH `/request-quote/` rewrite becomes a setting
  ("Host-relative path rewrites", default empty; `admin-ajax` always rewritten).

### 0.1.0

- 0.1.0: initial working crawler (resumable batches, asset downloader, form
  rewriting, `wp2static.js`, transient-500 retries).