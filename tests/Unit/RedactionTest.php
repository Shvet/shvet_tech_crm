<?php

namespace Tests\Unit;

use App\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactionTest extends TestCase
{
    public function test_sensitive_keys_are_masked_recursively(): void
    {
        $out = Redactor::redactArray([
            'access_token' => 'EAAB1234567890', 'app_secret' => 'abc', 'Authorization' => 'Bearer x',
            'nested' => ['password' => 'p', 'verify_token' => 'v', 'ok' => 'fine'],
            'message_body' => 'hello customer', 'lead_id' => 42,
        ]);

        $this->assertSame(Redactor::MASK, $out['access_token']);
        $this->assertSame(Redactor::MASK, $out['app_secret']);
        $this->assertSame(Redactor::MASK, $out['Authorization']);
        $this->assertSame(Redactor::MASK, $out['nested']['password']);
        $this->assertSame(Redactor::MASK, $out['nested']['verify_token']);
        $this->assertSame(Redactor::MASK, $out['message_body']);
        $this->assertSame('fine', $out['nested']['ok']);
        $this->assertSame(42, $out['lead_id']);
    }

    public function test_tokens_emails_and_phones_are_scrubbed_from_free_text(): void
    {
        $text = 'GET /v21.0/me?access_token=EAAGm0PX4ZCpsBAKzZB1234567890abcdefghijklmnopqrstuvwxyz&fields=id '
            .'Authorization: Bearer abc.def-ghi mail ravi.kumar@example.com call +91 98765 43210 secret=hunter2';

        $out = Redactor::redactString($text);

        foreach (['EAAGm0PX4ZCpsBAK', 'abc.def-ghi', 'ravi.kumar@example.com', '98765 43210', 'hunter2'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
        $this->assertStringContainsString('fields=id', $out);
    }
}
