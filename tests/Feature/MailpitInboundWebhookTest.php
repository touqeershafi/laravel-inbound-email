<?php

namespace Touqeershafi\LaravelInboundEmail\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Touqeershafi\LaravelInboundEmail\Jobs\DefaultProcessInboundEmailJob;
use Touqeershafi\LaravelInboundEmail\Tests\TestCase;

class MailpitInboundWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_rejects_when_webhook_secret_configured_and_header_missing(): void
    {
        config(['inbound-email.providers.mailpit.webhook_secret' => 'pit-secret']);

        Bus::fake();

        $this->postJson('/webhooks/inbound/mailpit', ['ID' => 'abc-1'])
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_rejects_when_webhook_secret_does_not_match(): void
    {
        config(['inbound-email.providers.mailpit.webhook_secret' => 'pit-secret']);

        Bus::fake();

        $this->postJson('/webhooks/inbound/mailpit', ['ID' => 'abc-1'], [
            'HTTP_X_WEBHOOK_SECRET' => 'wrong',
        ])->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_fetches_message_from_api_and_dispatches_job(): void
    {
        config(['inbound-email.providers.mailpit.webhook_secret' => '']);

        Bus::fake();

        Http::fake([
            '127.0.0.1:8825/api/v1/message/mp-1' => Http::response([
                'From' => ['Email' => 'from@local.test', 'Name' => 'From'],
                'To' => [['Email' => 'to@local.test', 'Name' => 'To']],
                'Cc' => [],
                'Bcc' => [],
                'Subject' => 'Mailpit subject',
                'Text' => 'Plain text',
                'HTML' => '<p>HTML</p>',
                'Attachments' => [],
                'Headers' => [],
            ], 200),
        ]);

        $this->postJson('/webhooks/inbound/mailpit', ['ID' => 'mp-1'])->assertOk();

        Bus::assertDispatched(DefaultProcessInboundEmailJob::class, function (DefaultProcessInboundEmailJob $job): bool {
            $m = $job->message;

            return ($m['provider'] ?? null) === 'mailpit'
                && ($m['subject'] ?? null) === 'Mailpit subject'
                && ($m['text'] ?? null) === 'Plain text'
                && ($m['metadata']['mailpit_id'] ?? null) === 'mp-1';
        });
    }

    public function test_accepts_alternate_id_casing_in_webhook_payload(): void
    {
        config(['inbound-email.providers.mailpit.webhook_secret' => '']);

        Bus::fake();

        Http::fake([
            '127.0.0.1:8825/api/v1/message/mp-2' => Http::response([
                'From' => ['Email' => 'a@b.com'],
                'To' => [['Email' => 'c@d.com']],
                'Subject' => 'Alt',
                'Text' => 'T',
                'HTML' => '',
                'Attachments' => [],
                'Headers' => [],
            ], 200),
        ]);

        $this->postJson('/webhooks/inbound/mailpit', ['id' => 'mp-2'])->assertOk();

        Bus::assertDispatched(DefaultProcessInboundEmailJob::class);
    }

    public function test_downloads_attachment_parts_from_the_api(): void
    {
        config(['inbound-email.providers.mailpit.webhook_secret' => '']);

        Bus::fake();

        Http::fake([
            '127.0.0.1:8825/api/v1/message/mp-att/part/2' => Http::response('%PDF-bytes', 200),
            '127.0.0.1:8825/api/v1/message/mp-att/part/3' => Http::response('', 500),
            '127.0.0.1:8825/api/v1/message/mp-att' => Http::response([
                'From' => ['Address' => 'from@local.test', 'Name' => 'From'],
                'To' => [['Address' => 'to@local.test', 'Name' => 'To']],
                'Subject' => 'With files',
                'Text' => 'See attached',
                'Attachments' => [
                    ['PartID' => '2', 'FileName' => 'invoice.pdf', 'ContentType' => 'application/pdf', 'Size' => 10],
                    ['PartID' => '3', 'FileName' => 'broken.png', 'ContentType' => 'image/png', 'Size' => 4],
                ],
                'Headers' => [],
            ], 200),
        ]);

        $this->postJson('/webhooks/inbound/mailpit', ['ID' => 'mp-att'])->assertOk();

        Bus::assertDispatched(DefaultProcessInboundEmailJob::class, function (DefaultProcessInboundEmailJob $job): bool {
            $attachments = $job->message['attachments'] ?? [];

            return count($attachments) === 2
                && $attachments[0]['filename'] === 'invoice.pdf'
                && $attachments[0]['content_type'] === 'application/pdf'
                && base64_decode($attachments[0]['content_base64'], true) === '%PDF-bytes'
                && $attachments[1]['filename'] === 'broken.png'
                && $attachments[1]['content_base64'] === '';
        });
    }
}
