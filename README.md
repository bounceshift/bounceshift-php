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
    'base_url' => 'https://api.bounceshift.com/v1', // default
    'timeout'  => 10,   // seconds
    'retries'  => 2,    // retry attempts on 429 / 5xx
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

`ValidationResult::isSafeToSend()` returns `true` only when the status is `valid` or `catch_all`.

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

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
