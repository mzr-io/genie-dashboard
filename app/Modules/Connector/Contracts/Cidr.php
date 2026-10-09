<?php

namespace App\Modules\Connector\Contracts;

/**
 * An IPv4 or IPv6 network in binary form: the network address (host bits zero) and the prefix length. Built only
 * from a strictly valid `address/prefix` text, so every comparison is done on `inet_pton` bytes, never on text.
 */
final readonly class Cidr
{
    private function __construct(
        public string $network,
        public int $bits,
    ) {}

    /** The network for `address/prefix` text, or null when it is not exactly that with the host bits zero. */
    public static function parse(string $text): ?self
    {
        if (preg_match('~\A([0-9a-fA-F:.]{2,45})/(0|[1-9][0-9]{0,2})\z~D', $text, $m) !== 1) {
            return null;
        }

        $binary = @inet_pton($m[1]);

        if ($binary === false) {
            return null;
        }

        $bits = (int) $m[2];

        if ($bits > strlen($binary) * 8) {
            return null;
        }

        $network = new self($binary, $bits);

        return $network->masked($binary) === $binary ? $network : null;
    }

    /** The one-address network of a binary address. */
    public static function address(string $binary): self
    {
        return new self($binary, strlen($binary) * 8);
    }

    public function text(): string
    {
        return inet_ntop($this->network).'/'.$this->bits;
    }

    public function contains(string $address): bool
    {
        return strlen($address) === strlen($this->network) && $this->masked($address) === $this->network;
    }

    /** True when every address of this network is in `$other`. */
    public function within(self $other): bool
    {
        return strlen($this->network) === strlen($other->network) && $this->bits >= $other->bits && $other->contains($this->network);
    }

    public function overlaps(self $other): bool
    {
        return $this->within($other) || $other->within($this);
    }

    /** The address with every bit after the prefix cleared. */
    private function masked(string $address): string
    {
        $bytes = intdiv($this->bits, 8);
        $remainder = $this->bits % 8;
        $kept = substr($address, 0, $bytes);

        if ($remainder > 0) {
            $kept .= chr(ord($address[$bytes]) & ((0xFF << (8 - $remainder)) & 0xFF));
            $bytes++;
        }

        return str_pad($kept, strlen($address), "\0");
    }
}
