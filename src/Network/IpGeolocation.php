<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Network;

final readonly class IpGeolocation
{
    public function __construct(
        public string $ip,
        public string $country,
        public string $isp,
    ) {
    }
}
