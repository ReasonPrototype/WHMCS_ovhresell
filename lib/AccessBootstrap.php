<?php

namespace OvhVps;

use phpseclib3\Crypt\EC;

/**
 * Automated first-boot access provisioning for plain VPS.
 *
 * OVH delivers a VPS with the password behind a manager link the customer cannot
 * use, and the API cannot set a chosen password. So we: generate a per-VPS
 * keypair, reinstall the VPS with the public key (no OVH password email), then
 * set a known password for the OS default user over SSH (see setPassword) so the
 * customer can log in through the browser console with no SSH tooling.
 *
 * n8n products do NOT use this (they are accessed over the web).
 */
class AccessBootstrap
{
    /**
     * Map the installed OS name to the cloud-image default sudo user. OVH VPS
     * cloud images log in as this user (e.g. Debian -> "debian"), not root.
     */
    public static function defaultUser(string $os): string
    {
        $os = strtolower($os);
        $map = [
            'ubuntu' => 'ubuntu',
            'debian' => 'debian',
            'alma' => 'almalinux',
            'rocky' => 'rocky',
            'centos' => 'centos',
            'fedora' => 'fedora',
        ];
        foreach ($map as $needle => $user) {
            if (str_contains($os, $needle)) {
                return $user;
            }
        }
        return 'debian'; // OVH VPS default
    }

    /**
     * Generate a fresh per-VPS ed25519 keypair in OpenSSH format.
     *
     * @return array{private:string, public:string}
     */
    public static function generateKeyPair(): array
    {
        $key = EC::createKey('Ed25519');
        return [
            'private' => (string) $key->toString('OpenSSH'),
            'public' => (string) $key->getPublicKey()->toString('OpenSSH'),
        ];
    }

    /**
     * Reinstall the VPS with our public key and no OVH password email.
     * DESTRUCTIVE: call only at initial delivery, never on a VPS already in use.
     */
    public static function installKey(OvhClient $client, string $serviceName, string $imageId, string $publicKey): void
    {
        $client->post('/vps/' . $serviceName . '/rebuild', [
            'imageId' => $imageId,
            'publicSshKey' => $publicKey,
            'doNotSendPassword' => true,
        ]);
    }
}
