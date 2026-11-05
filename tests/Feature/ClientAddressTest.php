<?php

namespace Tests\Feature;

use App\Services\Traffic\ClientAddress;
use Tests\TestCase;

/**
 * Client addresses are personal data, so how they are stored is a setting with
 * teeth: masking happens before the row is written, never on read.
 */
class ClientAddressTest extends TestCase
{
    protected ClientAddress $addresses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addresses = app(ClientAddress::class);
        config()->set('sentinel.traffic.store_client_ip', true);
        config()->set('sentinel.traffic.mask_client_ip', false);
    }

    public function test_it_keeps_a_valid_address(): void
    {
        $this->assertSame('203.0.113.7', $this->addresses->normalize('203.0.113.7'));
        $this->assertSame('2001:db8::1', $this->addresses->normalize('2001:db8::1'));
    }

    public function test_it_takes_the_original_caller_from_a_forwarded_chain(): void
    {
        $this->assertSame('203.0.113.7', $this->addresses->normalize('203.0.113.7, 198.51.100.1'));
    }

    public function test_it_rejects_anything_that_is_not_an_address(): void
    {
        $this->assertNull($this->addresses->normalize('not-an-ip'));
        $this->assertNull($this->addresses->normalize(''));
        $this->assertNull($this->addresses->normalize(null));
        $this->assertNull($this->addresses->normalize(['203.0.113.7']));
    }

    public function test_masking_drops_the_host_part(): void
    {
        config()->set('sentinel.traffic.mask_client_ip', true);

        // Still groups a subnet, so visitor counts and "one source is hammering
        // us" both survive; a single device no longer does.
        $this->assertSame('203.0.113.0', $this->addresses->normalize('203.0.113.7'));
        $this->assertSame('2001:db8:1::', $this->addresses->normalize('2001:db8:1:2:3:4:5:6'));
    }

    public function test_storage_can_be_refused_outright(): void
    {
        config()->set('sentinel.traffic.store_client_ip', false);

        $this->assertNull($this->addresses->normalize('203.0.113.7'));
    }
}
