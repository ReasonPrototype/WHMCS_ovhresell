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
 * over SSH (see setPassword) enable password login and set a known password for
 * the OS default user, so the customer can log in with that password either over
 * SSH (as the default user, sudo for root) or through the browser console.
 *
 * Every Linux image family uses this, including the free application images
 * (Docker, n8n): the rebuild-with-key reinstalls the SAME image, so the app
 * survives and the customer additionally gets root/console access. Windows is
 * the exception (see needsManualAccess): no SSH, no cloud-init default user,
 * and a rebuild would wipe the OVH-installed licensed Windows, so credentials
 * are delivered manually by the admin instead.
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
     * Whether an installed image must skip the SSH bootstrap entirely and go
     * through manual credential delivery instead. True for Windows: there is
     * no SSH daemon and no cloud-init default user to log in as, and the
     * rebuild-with-key would wipe the OVH-installed licensed Windows. The
     * cron routes these to a terminal 'manual' access state and notifies the
     * admin. Pure: no WHMCS/DB dependency.
     */
    public static function needsManualAccess(string $os): bool
    {
        return ConfigOptions::imageFamily($os) === 'windows';
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
     * Reinstall the VPS with an image that takes no SSH key (Windows), letting
     * OVH email the generated password to the OVH account owner (the reseller),
     * who then delivers it to the customer by hand. DESTRUCTIVE: call only on
     * an explicit customer-requested reinstall.
     */
    public static function reinstallImage(OvhClient $client, string $serviceName, string $imageId): void
    {
        $client->post('/vps/' . $serviceName . '/rebuild', [
            'imageId' => $imageId,
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
     * Build the remote shell command that provisions first-boot access over
     * SSH, as one &&-chain so the OVHVPS_PW_OK sentinel is printed only when
     * every step succeeds:
     *
     *   1. enable SSH password authentication via a drop-in that outranks OVH's
     *      cloud-init default, so the customer can log in as the OS default user
     *      with the emailed password (exactly what the delivery email promises);
     *   2. validate the resulting sshd config ("sshd -t") before trusting it;
     *   3. set the password for the default user AND root (console + SSH);
     *   4. apply it (reload, falling back to restart).
     *
     * Root stays console-only: we flip PasswordAuthentication but never
     * PermitRootLogin, so root is reachable through the KVM console, not by
     * password over SSH.
     *
     * LIVE-VERIFY: the 00- drop-in wins over OVH's cloud-init default
     * (50-cloud-init.conf sets PasswordAuthentication no) because sshd uses the
     * FIRST value found and reads the sshd_config.d drop-ins in lexical order,
     * so a 00- prefix is read before 50-. Assumes the image's sshd_config
     * carries the standard drop-in Include directive (true on every
     * Ubuntu/Debian/Alma/Rocky/CentOS/Fedora image we sell).
     *
     * Pure: assembles the string only (no SSH/WHMCS), so it is unit-tested
     * offline. Each shell-embedded value is single-quoted with the POSIX escape
     * ('\'' closes, escapes a literal quote, reopens) so the shell never sees an
     * unbalanced quote.
     */
    public static function bootstrapCommand(string $user, string $password): string
    {
        $u = str_replace("'", "'\\''", $user);
        $p = str_replace("'", "'\\''", $password);
        $dropIn = '/etc/ssh/sshd_config.d/00-ovhvps.conf';

        return "printf '%s\\n' 'PasswordAuthentication yes' | sudo tee " . $dropIn . " >/dev/null"
            . " && sudo sshd -t"
            . " && printf '%s\\n' '" . $u . ':' . $p . "' | sudo chpasswd"
            . " && printf '%s\\n' 'root:" . $p . "' | sudo chpasswd"
            . " && (sudo systemctl reload ssh || sudo systemctl restart ssh)"
            . " && echo OVHVPS_PW_OK";
    }

    /**
     * SSH in as $user with the private key, then run {@see bootstrapCommand()}
     * to enable SSH password login and set both that user's and root's password
     * (the default user has passwordless sudo on OVH cloud images), so the
     * customer can log in either over SSH or through the browser console with
     * the emailed password. Returns true on success. Bounded attempts so a
     * not-yet-booted VPS does not hang the cron; the cron re-runs on its next
     * tick if this returns false.
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
                    // One &&-chain (see bootstrapCommand): enable SSH password
                    // auth, set the user + root password, validate + reload
                    // sshd. The sentinel prints only if every step succeeds, so
                    // we never report success on access we did not provision.
                    $out = (string) $ssh->exec(self::bootstrapCommand($user, $password));
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
