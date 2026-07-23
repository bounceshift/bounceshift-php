# BounceShift PHP SDK

Framework-agnostic PHP client for the [BounceShift](https://bounceshift.com) email-validation API. Given an email address, BounceShift returns a status, a confidence score, and granular deliverability signals so you can protect your sender reputation before you hit send.

- PHP 8.2+
- PSR-18 / PSR-17 based — bring your own HTTP client or let it discover one
- Typed value objects and exceptions
- Automatic retries on `429` and `5xx` (honoring `Retry-After`)

API reference: <https://bounceshift.com/docs/api>

## Installation

```bash
composer require bounceshift/bounceshift-php
```

The SDK relies on [PSR-18 HTTP Client Discovery](https://docs.php-http.org/en/latest/discovery.html). Guzzle is included as a default implementation; any PSR-18 client works.

## Quickstart

```php
use BounceShift\Client;

$client = new Client(
    apiKey: 'your-api-key',
    organizationId: 'your-organization-id',
);

$result = $client->validate('user@example.com');

if ($result->isSafeToSend()) {
    // status is `valid` or `catch_all`
}

echo $result->status->value;   // e.g. "valid"
echo $result->confidence;      // 0-100
```

Both the API key **and** the organization ID are required — they are sent as the `Authorization: Bearer` and `X-Organization-ID` headers respectively.

## Configuration

```php
$client = new Client('your-api-key', 'your-organization-id', [
    'base_url'        => 'https://api.bounceshift.com/v1', // default
    'timeout'         => 10,   // seconds — max wait for a response (enforced)
    'connect_timeout' => 5,    // seconds — max wait to establish the connection
    'retries'         => 2,    // retry attempts on 429 / 5xx
    // Optionally inject your own PSR-18 / PSR-17 implementations:
    // 'http_client'     => $psr18Client,
    // 'request_factory' => $psr17RequestFactory,
    // 'stream_factory'  => $psr17StreamFactory,
]);
```

## The result object

`Client::validate()` returns an immutable `BounceShift\ValidationResult`:

| Property        | Type                | Description                                    |
| --------------- | ------------------- | ---------------------------------------------- |
| `email`         | `string`            | The address that was validated.                |
| `status`        | `ValidationStatus`  | The overall verdict (see below).               |
| `confidence`    | `int`               | Confidence score, 0–100.                       |
| `mxFound`       | `bool`              | Whether MX records were found.                 |
| `smtpValid`     | `?bool`             | SMTP probe result, or `null` if inconclusive.  |
| `isDisposable`  | `bool`              | Disposable/temporary provider.                 |
| `isCatchAll`    | `bool`              | Domain accepts all mail.                        |
| `isRoleAccount` | `bool`              | Role address (e.g. `info@`, `support@`).       |
| `fromCache`     | `bool`              | Served from a cached prior result.             |
| `creditsUsed`   | `int`               | Credits consumed by this call.                 |
| `result`        | `array`             | Freeform sub-status detail.                    |
| `subStatus`         | `?string`          | Granular reason for the verdict (e.g. `smtp_verified`), or `null`. |
| `recommendation`    | `?Recommendation`  | Actionable send recommendation, or `null` when absent or unrecognized. |
| `recommendationValue` | `?string`        | Raw recommendation string as sent by the API, preserved even when unrecognized. |
| `qualityScore`      | `?int`             | Quality score, 0–100. Distinct from `confidence`; `null` when absent. |
| `explanation`       | `?string`          | Plain-English sentence describing the verdict, or `null`. |
| `didYouMean`        | `?string`          | Corrected address when the domain looks like a misspelling (`gmial.com` → `gmail.com`), or `null`. |

`ValidationResult::isSafeToSend()` returns `true` only when the status is `valid` or `catch_all`.

`ValidationResult::isSendable()` surfaces the API's actionable recommendation instead: it returns `true` only when `recommendation` is `deliverable` or `send_with_caution`. An absent, `null`, or unrecognized recommendation is treated as not sendable (and never throws).

```php
$result = $client->validate('user@example.com');

if ($result->isSendable()) {
    // recommendation is `deliverable` or `send_with_caution`
}

echo $result->recommendation?->value;  // e.g. "deliverable", or null
echo $result->qualityScore;            // 0-100, or null
echo $result->explanation;             // plain-English verdict
```

### Typo suggestions

`didYouMean` carries the corrected address when the domain is within a character or two of a major provider, and `null` otherwise. `hasSuggestion()` is the convenience check.

```php
$result = $client->validate('grace@gmil.com');

if ($result->hasSuggestion()) {
    // "grace@gmail.com" — show it, don't swap it
    echo $result->didYouMean;
}
```

Two things to know:

- **It is advisory.** The API validates the address you sent, never the suggestion, and the verdict is unaffected. Put the correction in front of the person who typed it rather than substituting it — the mailbox at the misspelled domain may genuinely exist, and swapping it silently means mailing an address you were never given.
- **It is populated on any status**, including `valid` and `disposable`. That is deliberate: misspellings like `gmil.com` and `hotmial.com` are registered and accept mail, so they never bounce and never appear in a bounce report. The suggestion is the only signal you get for them.

### Recommendations

`BounceShift\Recommendation` is a string-backed enum:

`deliverable`, `send_with_caution`, `risky`, `undeliverable`, `unknown`.

```php
use BounceShift\Recommendation;

Recommendation::Deliverable->isSendable();     // true
Recommendation::SendWithCaution->isSendable(); // true
Recommendation::Risky->isSendable();           // false
Recommendation::Undeliverable->isSendable();   // false
Recommendation::Unknown->isSendable();         // false
```

### Statuses

`BounceShift\ValidationStatus` is a string-backed enum:

`valid`, `invalid`, `risky`, `catch_all`, `unknown`, `disposable`, `spamtrap`, `abuse`, `do_not_mail`.

```php
use BounceShift\ValidationStatus;

ValidationStatus::Valid->isSafeToSend();    // true
ValidationStatus::CatchAll->isSafeToSend(); // true
ValidationStatus::Invalid->isSafeToSend();  // false
```

## Error handling

All exceptions extend `BounceShift\Exceptions\BounceShiftException`. API errors extend `ApiException`, which exposes `$statusCode` and `$body`.

```php
use BounceShift\Exceptions\AuthenticationException;
use BounceShift\Exceptions\ForbiddenException;
use BounceShift\Exceptions\InsufficientCreditsException;
use BounceShift\Exceptions\RateLimitException;
use BounceShift\Exceptions\ApiException;

try {
    $result = $client->validate('user@example.com');
} catch (AuthenticationException $e) {      // 401
} catch (InsufficientCreditsException $e) { // 402
} catch (ForbiddenException $e) {           // 403
} catch (RateLimitException $e) {           // 429
    $retryAfter = $e->retryAfter;           // ?int seconds
} catch (ApiException $e) {                 // any other non-2xx
    $status = $e->statusCode;
    $body   = $e->body;
}
```

Your API key is never included in exception messages or logs.

## Fail open — never block your users

On a hot path such as validate-on-signup, a validation problem should never block
the user. If your account runs out of credits, or the API is down, timing out, or
unreachable, you almost always want to let the address through rather than crash
the signup. Use `validateSafe()` — it never throws:

```php
$result = $client->validateSafe('user@example.com');

if ($result->isDegraded()) {
    // We couldn't reach a verdict (out of credits / outage / timeout).
    // The address is returned unverified — let it through, and alert your ops.
} elseif (! $result->isSafeToSend()) {
    // A real verdict came back and it's not safe — reject as usual.
}
```

A degraded result has status `unknown`, `creditsUsed = 0`, and
`isDegraded() === true`, so you can always tell "we couldn't check" apart from a
genuine `unknown` verdict. Reach for `validate()` (which throws the typed
exceptions above) only when you want to handle each failure yourself.

The `timeout` / `connect_timeout` options bound how long a stalled API can hold
your request thread before `validateSafe()` returns a degraded result.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
