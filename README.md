# Noria Send for Laravel

Send transactional email and SMS through [Noria Send](https://github.com/norialabs/send)
instead of wiring SES and OnFon into every product. Registers a Laravel mail transport and an
SMS notification channel, so `Mail::send()` and `$user->notify()` keep working exactly as they
do today.

```bash
composer require norialabs/send
```

This repository is a read-only split of `sdks/php` in
[`norialabs/send`](https://github.com/norialabs/send). Open pull requests there; the mirror
is force-pushed on every release and anything committed here is lost.

```env
MAIL_MAILER=noria
NORIA_SEND_KEY=nm_live_…
```

`NORIA_SEND_URL` defaults to `https://send.noria.co.ke`; set it to reach a local service or
another instance.

Everything else the package reads, with its default:

| Variable | Default | What it does |
| --- | --- | --- |
| `NORIA_SEND_TIMEOUT` | `15` | Seconds to wait on a single HTTP request |
| `NORIA_SEND_RETRIES` | `2` | Retries after a connection failure or a 5xx |
| `NORIA_SEND_FAIL_ON_SUPPRESSED` | `false` | Whether sending to a suppressed address throws |
| `NORIA_SEND_WEBHOOK_SECRET` | none | Secret shown once when the webhook endpoint was created |
| `NORIA_SEND_WEBHOOK_TOLERANCE` | `300` | Seconds a webhook signature stays valid |

Sending never blocks on the provider, so the timeout covers the enqueue call rather than
delivery, and two retries are about a service restart rather than a bounce.

```php
// config/mail.php
'mailers' => [
    'noria' => ['transport' => 'noria'],
],
```

That is the whole email integration. Every `Mail::send()`, `Mail::to()->queue()`, notification
and mailable in the application now goes through the service and gets queueing, retries,
suppression, delivery events and per-workspace isolation without touching a call site.

## SMS

SMS arrives as a notification channel, which is how Laravel expects to send it.

```php
use Illuminate\Notifications\Notification;
use NoriaLabs\Send\Notifications\SmsMessage;

class OtpIssued extends Notification
{
    public function __construct(private string $code) {}

    public function via($notifiable): array
    {
        return ['send-sms'];
    }

    public function toSendSms($notifiable): SmsMessage
    {
        return SmsMessage::make("Your code is {$this->code}")
            ->sender('NORIA')
            ->tags(['kind' => 'otp'])
            ->idempotencyKey("otp-{$this->code}");
    }
}
```

The notifiable says where it goes:

```php
public function routeNotificationForSendSms(): string
{
    return $this->phone;
}
```

`toSendSms()` may also return a plain string. To send a stored template instead of a body,
use `SmsMessage::make()->template('otp', ['code' => $code])`.

## Beyond the transport

The client is bound in the container and available as a facade, grouped by resource.

```php
use NoriaLabs\Send\Facades\Send;

Send::sms()->send(['from' => 'NORIA', 'to' => '0712345678', 'text' => 'Your code is 482913']);
Send::domains()->create('norialabs.com');       // returns the DNS records to publish
Send::senders()->create('NORIA');               // registered pending approval
Send::templates()->upsert(['slug' => 'otp', 'channel' => 'sms', 'text' => 'Code {{code}}']);
Send::suppressions()->add('0712345678', 'sms', 'unsubscribe');
Send::suppressions()->has('0712345678', 'sms');
Send::messages()->list(['channel' => 'sms', 'status' => 'failed']);
Send::messages()->events($messageId);
Send::messages()->requeue($messageId);
```

Each accessor returns a typed resource object, so PHPStan resolves the methods on it without a
hand-maintained `@method` list.

## Per-message options

Set headers on a mailable; the transport strips them and maps them onto the API.

```php
use NoriaLabs\Send\SendTransport;

Mail::html($body, function ($message) {
    $message->to($user->email)->subject('Your sign-in link');

    $headers = $message->getHeaders();
    $headers->addTextHeader(SendTransport::TAG_HEADER.'-product', 'zana');
    $headers->addTextHeader(SendTransport::IDEMPOTENCY_HEADER, "signin-{$token->id}");
    $headers->addTextHeader(SendTransport::SCHEDULE_HEADER, now()->addHour()->toIso8601String());
});
```

| Header | Effect |
| --- | --- |
| `X-Noria-Tag-<name>` | Becomes a tag on the message, queryable and forwarded to SES |
| `X-Noria-Idempotency-Key` | Re-sending with the same key returns the original message |
| `X-Noria-Template` | Render a stored template instead of the body |
| `X-Noria-Variables` | JSON variables for that template |
| `X-Noria-Scheduled-At` | ISO 8601 time to send at |

Any other custom header is passed through to the message itself.

## Suppressed recipients

By default a send to a suppressed address or number is a no-op rather than an exception, so one
opt-out cannot fail a queued job or a batch notification. Set `NORIA_SEND_FAIL_ON_SUPPRESSED`
to raise `SendException` instead.

## Webhooks

```php
use NoriaLabs\Send\WebhookVerifier;

Route::post('/webhooks/send', function (Request $request, WebhookVerifier $verifier) {
    $event = $verifier->verify($request->getContent(), $request->header('Noria-Signature', ''));

    // $event['data']['channel'] is email or sms
    // $event['type'] is delivered, bounced, complained, opened, clicked, failed, …
});
```

An interrupted send is retried by the client, and the client attaches an idempotency key of its
own so the retry is a replay rather than a second message. Pass your own key when you want
that guarantee to span your retries too, not only the SDK's.

Set `NORIA_SEND_WEBHOOK_SECRET` to the secret shown once when the endpoint was created.

Delivery is at-least-once: a retry or a provider redelivery can bring the same event twice.
`$event['id']` — the `Noria-Event-Id` header — is stable across both, so record it and ignore an id
you have already handled.

## Requirements

PHP 8.3 or newer, and **Laravel 13**. Laravel 11 and 12 were dropped: every Noria product is
moving to 13, and supporting three majors meant the test suite ran against 12 and never against
the version we actually ship on — which is how a Laravel 13 container-binding change sat
unnoticed. `^8.3` is the framework's own floor and admits 8.6 when it arrives.

## Tests

```bash
composer quality      # pint, phpstan level max, pest
```

`tests/LiveTest.php` runs against a real instance and is skipped unless you point it at one:

```bash
NORIA_SEND_LIVE_KEY=nm_live_… vendor/bin/pest tests/LiveTest.php
```

That reaches `http://localhost:4800`, so it exercises a service you are running yourself. Set
`NORIA_SEND_LIVE_URL` to aim it elsewhere. The SMS case additionally needs
`NORIA_SEND_LIVE_MSISDN` set to a number you control, because it sends a real message.
