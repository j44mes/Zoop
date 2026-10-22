# Letting Zoop check the published page

Zoop reads a published page by requesting it from the site itself. The request uses a Zoop user agent, for example `Zoop/0.6.0-beta.1 (+https://github.com/j44mes/Zoop)`. It does not pretend to be Googlebot or another crawler.

If that request is blocked, Zoop keeps the last successful result and does not save a new score from the blocked response. Choose **Try again** after the page can be fetched.

## Password protection

Some staging sites ask for a username and password before any page is shown. Put those HTTP basic-authentication values in `wp-config.php`. Zoop reads them when it fetches a page and does not store them in the database.

```php
define( 'SEOSTUDIO_FETCH_USER', 'the-username' );
define( 'SEOSTUDIO_FETCH_PASS', 'the-password' );
```

Use the credentials for the published-page gate, not a WordPress password, and do not commit them to the repository.

## A host protection page or bot check

A host such as SiteGround can answer with a CAPTCHA or an interstitial, sometimes as HTTP 202 and sometimes as HTTP 200. Zoop cannot complete the check from that page.

Ask the host to allow requests that come from the site itself and send the Zoop user agent above. Leave the protection in place for other clients. After the host allows the request, open the page in Zoop and choose **Try again**.

## What this is not

Published Evidence Recovery, a resumable check of every page that is still missing published-page evidence, is not implemented. This note covers one page check and the two ways a host can let that check through.
