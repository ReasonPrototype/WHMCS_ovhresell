<?php

namespace OvhVps;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Automated first-boot access provisioning.
 *
 * OVH delivers a VPS with the password behind a manager link the customer cannot
 * use, and the API cannot set a chosen password. So we: generate a per-VPS
 * keypair, reinstall the VPS with the public key (no OVH password email), then
 * set a known password for the OS default user over SSH (see setPassword) so the
 * customer can log in through the browser console with no SSH tooling.
 *
 * Every image family uses this, including the free application images (Docker,
 * n8n): the rebuild-with-key reinstalls the SAME image, so the app survives and
 * the customer additionally gets root/console access.
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

    /**
     * Strong random password, drawn from an unambiguous alphabet (no 0/O/1/l/I)
     * so it is safe to read off the console and type. Uses random_int (CSPRNG).
     */
    public static function generatePassword(int $len = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * SSH in as $user with the private key and set both that user's and root's
     * password (the default user has passwordless sudo on OVH cloud images), so
     * the customer can log in through the browser console with no SSH tooling.
     * Returns true on success. Bounded attempts so a not-yet-booted VPS does not
     * hang the cron; the cron re-runs on its next tick if this returns false.
     *
     * LIVE-VERIFY: assumes the default user has passwordless sudo and the image
     * does not enforce `requiretty`. If sudo needs a TTY, enable a PTY before
     * exec or switch to `sudo -n`.
     */
    public static function setPassword(string $ip, string $user, string $privateKey, string $password, int $attempts = 3, int $sleepSeconds = 10): bool
    {
        if ($ip === '' || $user === '' || $privateKey === '') {
            return false;
        }
        $key = PublicKeyLoader::load($privateKey);
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $ssh = new SSH2($ip, 22, 20);
                if ($ssh->login($user, $key)) {
                    // Single-quote the "user:pass" payload; escape any quote in
                    // the password the POSIX way ('\'' closes, escapes, reopens).
                    // Chain with && so the sentinel prints only if BOTH chpasswd
                    // calls succeed: never report success on a password we did
                    // not actually set (which would email a broken login).
                    $escaped = str_replace("'", "'\\''", $password);
                    $cmd = "printf '%s\\n' '" . $user . ':' . $escaped . "' | sudo chpasswd"
                        . " && printf '%s\\n' 'root:" . $escaped . "' | sudo chpasswd"
                        . " && echo OVHVPS_PW_OK";
                    $out = (string) $ssh->exec($cmd);
                    $ssh->disconnect();
                    if (strpos($out, 'OVHVPS_PW_OK') !== false) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // VPS may still be installing/booting; retry on the next pass.
            }
            if ($i < $attempts - 1) {
                sleep($sleepSeconds);
            }
        }
        return false;
    }
}
