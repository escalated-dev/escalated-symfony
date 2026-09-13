<?php

declare(strict_types=1);

namespace Escalated\Symfony\Http;

/**
 * Decides whether Escalated may send a webhook to a URL.
 *
 * A webhook makes the server POST to an address an admin typed in, and the
 * response is stored in the delivery log. Without a check that is a way to
 * reach -- and read -- the host's own network: a local Redis, the cloud
 * metadata endpoint, a private service. So only http and https are allowed,
 * and the host must be, and resolve to, public addresses.
 *
 * Checked twice:
 *  - check() when a webhook is saved, so the admin gets an error;
 *  - resolve() on every delivery, because a host can resolve elsewhere later
 *    and webhooks saved before this check existed are still stored. The
 *    transport connects to the address resolve() approved.
 *
 * `escalated.webhooks.allow_private_networks` turns the address check off for
 * hosts that deliver to internal receivers on purpose. The scheme check stays.
 */
class WebhookUrlGuard
{
    private readonly \Closure $resolver;

    /**
     * @param (\Closure(string): list<string>)|null $resolver host => IP addresses; defaults to DNS
     */
    public function __construct(
        private readonly bool $allowPrivateNetworks = false,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::dnsResolver(...);
    }

    /**
     * Save-time check. Returns an error message, or null when the URL may be
     * stored. A host that does not resolve yet is accepted here; delivery
     * checks it again.
     */
    public function check(string $url): ?string
    {
        $parts = $this->parse($url);
        if (\is_string($parts)) {
            return $parts;
        }

        foreach ($this->addresses($parts['host']) as $address) {
            if (!$this->isAllowedAddress($address)) {
                return 'Webhook URLs must point to a public address; '.$parts['host'].' is on a private or reserved network.';
            }
        }

        return null;
    }

    /**
     * Delivery-time check. Returns the host, port and the address to connect
     * to, or throws when the target is not allowed.
     *
     * @return array{host: string, port: int, address: string}
     *
     * @throws WebhookTransportException
     */
    public function resolve(string $url): array
    {
        $parts = $this->parse($url);
        if (\is_string($parts)) {
            throw new WebhookTransportException('Webhook delivery not allowed: '.$parts);
        }

        $addresses = $this->addresses($parts['host']);
        if ([] === $addresses) {
            throw new WebhookTransportException('Webhook delivery not allowed: '.$parts['host'].' could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (!$this->isAllowedAddress($address)) {
                throw new WebhookTransportException('Webhook delivery not allowed: '.$parts['host'].' resolves to a private or reserved address.');
            }
        }

        return ['host' => $parts['host'], 'port' => $parts['port'], 'address' => $addresses[0]];
    }

    /**
     * @return array{host: string, port: int}|string the parts, or an error message
     */
    private function parse(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URLs must use http or https.';
        }

        $host = (string) ($parts['host'] ?? '');
        if ('' === $host) {
            return 'Webhook URLs must include a host.';
        }

        return [
            'host' => trim($host, '[]'),
            'port' => (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80)),
        ];
    }

    /**
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return array_values(array_unique(($this->resolver)($host)));
    }

    private function isAllowedAddress(string $address): bool
    {
        if ($this->allowPrivateNetworks) {
            return false !== filter_var($address, \FILTER_VALIDATE_IP);
        }

        // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) is judged as the IPv4
        // address it carries.
        if (1 === preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $mapped)) {
            $address = $mapped[1];
        }

        return false !== filter_var(
            $address,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * @return list<string>
     */
    private static function dnsResolver(string $host): array
    {
        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
