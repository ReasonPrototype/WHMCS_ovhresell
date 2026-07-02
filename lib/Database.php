<?php

namespace OvhVps;

use WHMCS\Database\Capsule;

/**
 * Schema management and data-access helpers for the module's own tables.
 */
class Database
{
    public const SERVERS = 'mod_ovhvps_servers';
    public const ORDERS = 'mod_ovhvps_orders';
    public const TASKLOG = 'mod_ovhvps_tasklog';
    public const OPTION_MAP = 'mod_ovhvps_option_map';
    public const CAT_PLANS = 'mod_ovhvps_cat_plans';
    public const CAT_DATACENTERS = 'mod_ovhvps_cat_datacenters';
    public const CAT_OS = 'mod_ovhvps_cat_os';
    public const CAT_OPTIONS = 'mod_ovhvps_cat_options';
    public const AVAILABILITY = 'mod_ovhvps_availability';
    public const META = 'mod_ovhvps_meta';

    /** Custom WHMCS email template the cron sends when a VPS is ready (any image). */
    public const EMAIL_TEMPLATE = 'OVH VPS Access Ready';

    /** Additional template sent right after the access email when the installed image is n8n. */
    public const EMAIL_TEMPLATE_N8N = 'OVH n8n Access Ready';

    /** Custom WHMCS email template for a customer-chosen password change. */
    public const EMAIL_TEMPLATE_PWCHANGED = 'OVH VPS Password Changed';

    /** Credential-free template sent instead of the access email when the image is Windows. */
    public const EMAIL_TEMPLATE_WINDOWS = 'OVH VPS Windows Ready';

    /** Custom WHMCS email template sent when a model upgrade has completed. */
    public const EMAIL_TEMPLATE_UPGRADE = 'OVH VPS Upgrade Complete';

    /** Bumped whenever the module-managed template bodies change, to force the
     *  new version onto existing installs once (see ensureEmailTemplate). */
    public const EMAIL_TPL_REV = '4';

    /**
     * Create any missing tables. Idempotent; safe to call on every request.
     */
    public static function ensureSchema(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::SERVERS)) {
            $schema->create(self::SERVERS, static function ($t): void {
                $t->increments('id');
                $t->integer('service_id')->unsigned()->unique();
                $t->string('service_name')->nullable();
                $t->string('order_id', 64)->nullable();
                $t->string('endpoint', 32)->nullable();
                $t->string('subsidiary', 8)->nullable();
                $t->string('plan_code')->nullable();
                $t->string('datacenter', 32)->nullable();
                $t->string('os')->nullable();
                $t->string('state', 32)->nullable();
                $t->string('ip_main', 64)->nullable();
                $t->text('model_json')->nullable();
                $t->string('display_name')->nullable();
                $t->boolean('delete_at_expiration')->default(false);
                $t->timestamps();
            });
        }

        if (!$schema->hasTable(self::ORDERS)) {
            $schema->create(self::ORDERS, static function ($t): void {
                $t->increments('id');
                $t->integer('service_id')->unsigned()->index();
                $t->string('cart_id', 64)->nullable();
                $t->string('item_id', 64)->nullable();
                $t->string('order_id', 64)->nullable();
                $t->string('status', 32)->default('pending');
                $t->boolean('terminate_token_pending')->default(false);
                $t->decimal('expected_cost', 12, 2)->nullable();
                $t->string('currency', 8)->nullable();
                $t->longText('request_json')->nullable();
                $t->longText('response_json')->nullable();
                $t->timestamps();
            });
        }

        if (!$schema->hasTable(self::TASKLOG)) {
            $schema->create(self::TASKLOG, static function ($t): void {
                $t->increments('id');
                $t->integer('service_id')->unsigned()->nullable()->index();
                $t->string('actor', 16)->default('system');
                $t->string('action', 64);
                $t->string('ovh_task_id', 64)->nullable();
                $t->integer('http_status')->nullable();
                $t->longText('request')->nullable();
                $t->longText('response')->nullable();
                $t->boolean('success')->default(true);
                $t->text('message')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::OPTION_MAP)) {
            $schema->create(self::OPTION_MAP, static function ($t): void {
                $t->increments('id');
                $t->integer('pid')->unsigned()->index();
                $t->string('whmcs_option_group')->nullable();
                $t->string('whmcs_option_value')->nullable();
                $t->string('ovh_kind', 16);
                $t->string('ovh_label')->nullable();
                $t->string('ovh_value')->nullable();
                $t->string('ovh_option_plan_code')->nullable();
                $t->timestamps();
            });
        }

        if (!$schema->hasTable(self::CAT_PLANS)) {
            $schema->create(self::CAT_PLANS, static function ($t): void {
                $t->increments('id');
                $t->string('endpoint', 32)->index();
                $t->string('subsidiary', 8)->index();
                $t->string('plan_code');
                $t->string('description')->nullable();
                $t->longText('raw_json')->nullable();
                $t->timestamp('synced_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::CAT_DATACENTERS)) {
            $schema->create(self::CAT_DATACENTERS, static function ($t): void {
                $t->increments('id');
                $t->string('endpoint', 32)->index();
                $t->string('subsidiary', 8)->index();
                $t->string('plan_code')->nullable()->index();
                $t->string('datacenter', 32);
                $t->string('name')->nullable();
                $t->boolean('in_stock')->default(true);
                $t->timestamp('synced_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::CAT_OS)) {
            $schema->create(self::CAT_OS, static function ($t): void {
                $t->increments('id');
                $t->string('endpoint', 32)->index();
                $t->string('subsidiary', 8)->index();
                $t->string('plan_code')->nullable()->index();
                $t->string('os');
                $t->boolean('available')->default(true);
                $t->timestamp('synced_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::CAT_OPTIONS)) {
            $schema->create(self::CAT_OPTIONS, static function ($t): void {
                $t->increments('id');
                $t->string('endpoint', 32)->index();
                $t->string('subsidiary', 8)->index();
                $t->string('plan_code')->nullable()->index();
                $t->string('family', 32)->nullable();
                $t->string('option_plan_code');
                $t->string('description')->nullable();
                $t->boolean('mandatory')->default(false);
                $t->boolean('is_default')->default(false);
                $t->longText('raw_json')->nullable();
                $t->timestamp('synced_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::AVAILABILITY)) {
            $schema->create(self::AVAILABILITY, static function ($t): void {
                $t->increments('id');
                $t->string('endpoint', 32);
                $t->string('subsidiary', 8);
                $t->string('plan_code');
                $t->boolean('available')->default(false);
                $t->text('datacenters_json')->nullable();
                $t->longText('raw_json')->nullable();
                $t->timestamp('checked_at')->nullable();
                $t->unique(['endpoint', 'subsidiary', 'plan_code'], 'ovhvps_avail_uniq');
            });
        }

        if (!$schema->hasTable(self::META)) {
            $schema->create(self::META, static function ($t): void {
                $t->string('mkey', 64)->primary();
                $t->text('mvalue')->nullable();
            });
        }

        // Additive migrations for existing installs (the hasTable blocks above
        // only run on first create; new columns need their own guards).
        if ($schema->hasTable(self::SERVERS) && !$schema->hasColumn(self::SERVERS, 'options_json')) {
            $schema->table(self::SERVERS, static function ($t): void {
                $t->longText('options_json')->nullable();
            });
        }
        if ($schema->hasTable(self::ORDERS) && !$schema->hasColumn(self::ORDERS, 'kind')) {
            $schema->table(self::ORDERS, static function ($t): void {
                $t->string('kind', 32)->default('create');
            });
        }
        if ($schema->hasTable(self::ORDERS) && !$schema->hasColumn(self::ORDERS, 'vps_before_json')) {
            $schema->table(self::ORDERS, static function ($t): void {
                $t->longText('vps_before_json')->nullable();
            });
        }
        if ($schema->hasTable(self::CAT_OPTIONS) && !$schema->hasColumn(self::CAT_OPTIONS, 'mandatory')) {
            $schema->table(self::CAT_OPTIONS, static function ($t): void {
                $t->boolean('mandatory')->default(false);
                $t->boolean('is_default')->default(false);
            });
        }

        // Phase B access bootstrap: per-VPS key + console password (encrypted)
        // and the bootstrap state-machine flag. All nullable/additive.
        $accessCols = [
            'ssh_pubkey' => 'text',
            'ssh_privkey_enc' => 'longtext',
            'root_user' => 'string',
            'root_pass_enc' => 'text',
            'access_state' => 'string',
        ];
        foreach ($accessCols as $accCol => $accType) {
            if (!$schema->hasTable(self::SERVERS) || $schema->hasColumn(self::SERVERS, $accCol)) {
                continue;
            }
            $schema->table(self::SERVERS, static function ($t) use ($accCol, $accType): void {
                if ($accType === 'string') {
                    $t->string($accCol)->nullable();
                } elseif ($accType === 'text') {
                    $t->text($accCol)->nullable();
                } else {
                    $t->longText($accCol)->nullable();
                }
            });
        }
    }

    /**
     * Create the access-ready email template once (idempotent): an English base
     * plus a Portuguese variant. The cron sends it when a VPS is ready (any image).
     *
     * LIVE-VERIFY: confirm the tblemailtemplates columns and multi-language
     * behaviour on the target WHMCS version. If the 'portuguese' row is not
     * picked up, add the translation in Setup -> Email Templates (the base
     * English template always works).
     */
    public static function ensureEmailTemplate(): void
    {
        if (!Capsule::schema()->hasTable('tblemailtemplates')) {
            return;
        }

        $templates = self::emailTemplates();
        foreach ($templates as $t) {
            self::ensureOneTemplate($t['name'], $t['subjectEn'], $t['bodyEn'], $t['subjectPt'], $t['bodyPt']);
        }

        // These templates are module-managed. When their bodies change (a new
        // EMAIL_TPL_REV), force the current version onto existing installs ONCE,
        // so restructures (e.g. the richer access email) actually land. Keyed by a
        // meta flag so admin edits are not clobbered on every request.
        if (self::getMeta('email_tpl_rev') !== self::EMAIL_TPL_REV) {
            foreach ($templates as $t) {
                self::forceTemplate($t['name'], $t['subjectEn'], $t['bodyEn'], $t['subjectPt'], $t['bodyPt']);
            }
            self::setMeta('email_tpl_rev', self::EMAIL_TPL_REV);
        }
    }

    /**
     * The module-managed email templates (English base + Portuguese variant).
     * Single source used both to insert-if-missing and to force the current rev.
     *
     * @return list<array{name:string,subjectEn:string,bodyEn:string,subjectPt:string,bodyPt:string}>
     */
    private static function emailTemplates(): array
    {
        return [
            [
                'name' => self::EMAIL_TEMPLATE,
                'subjectEn' => 'Your VPS is ready',
                'bodyEn' => '<p>Hello {$client_name},</p>'
                    . '<p>Your VPS <strong>{$service_domain}</strong> is ready, running {$os}.</p>'
                    . '<p><strong>Access details</strong></p>'
                    . '<ul>'
                    . '<li>IPv4: {$ipv4}</li>'
                    . '{if $ipv6}<li>IPv6: {$ipv6}</li>{/if}'
                    . '<li>SSH user: {$ssh_user} (use sudo for root)</li>'
                    . '<li>Root (console): root</li>'
                    . '<li>Password: {$root_password}</li>'
                    . '</ul>'
                    . '<p>For console (KVM) access, open the Console tab in your client area'
                    . '{if $service_url}: <a href="{$service_url}">{$service_url}</a>{/if}.</p>'
                    . '{$signature}',
                'subjectPt' => 'O seu VPS está pronto',
                'bodyPt' => '<p>Olá {$client_name},</p>'
                    . '<p>O seu VPS <strong>{$service_domain}</strong> está pronto, com o sistema {$os}.</p>'
                    . '<p><strong>Detalhes de acesso</strong></p>'
                    . '<ul>'
                    . '<li>IPv4: {$ipv4}</li>'
                    . '{if $ipv6}<li>IPv6: {$ipv6}</li>{/if}'
                    . '<li>Utilizador SSH: {$ssh_user} (use sudo para root)</li>'
                    . '<li>Root (consola): root</li>'
                    . '<li>Palavra-passe: {$root_password}</li>'
                    . '</ul>'
                    . '<p>Para a consola (KVM), abra o separador Consola na sua área de cliente'
                    . '{if $service_url}: <a href="{$service_url}">{$service_url}</a>{/if}.</p>'
                    . '{$signature}',
            ],
            [
                'name' => self::EMAIL_TEMPLATE_N8N,
                'subjectEn' => 'Your n8n is ready',
                'bodyEn' => '<p>Hello {$client_name},</p>'
                    . '<p>Your VPS was installed with the n8n image and the editor is ready.</p>'
                    . '<ul>'
                    . '<li>n8n URL: <a href="http://{$service_dedicated_ip}:5678">http://{$service_dedicated_ip}:5678</a></li>'
                    . '<li>Server IP: {$service_dedicated_ip}</li>'
                    . '</ul>'
                    . '<p>Open the URL in your browser and create your owner account on the first visit. '
                    . 'Reinstalling the n8n image wipes the server and resets the owner account. '
                    . 'If you enabled HTTPS or a reverse proxy on the server, use that address instead.</p>'
                    . '<p>Your root access details were sent in a separate email.</p>'
                    . '{$signature}',
                'subjectPt' => 'O seu n8n está pronto',
                'bodyPt' => '<p>Olá {$client_name},</p>'
                    . '<p>O seu VPS foi instalado com a imagem n8n e o editor está pronto.</p>'
                    . '<ul>'
                    . '<li>URL do n8n: <a href="http://{$service_dedicated_ip}:5678">http://{$service_dedicated_ip}:5678</a></li>'
                    . '<li>IP do servidor: {$service_dedicated_ip}</li>'
                    . '</ul>'
                    . '<p>Abra o URL no browser e crie a sua conta de proprietário na primeira visita. '
                    . 'Reinstalar a imagem n8n apaga o servidor e repõe a conta de proprietário. '
                    . 'Se ativou HTTPS ou um reverse proxy no servidor, use esse endereço.</p>'
                    . '<p>Os seus dados de acesso root foram enviados num email separado.</p>'
                    . '{$signature}',
            ],
            [
                'name' => self::EMAIL_TEMPLATE_WINDOWS,
                'subjectEn' => 'Your Windows VPS is ready',
                'bodyEn' => '<p>Hello {$client_name},</p>'
                    . '<p>Your VPS <strong>{$service_domain}</strong> was installed with Windows and is now online.</p>'
                    . '<ul>'
                    . '<li>IP: {$service_dedicated_ip}</li>'
                    . '</ul>'
                    . '<p>For security reasons your login credentials are delivered separately by our team. You will receive them shortly.</p>'
                    . '<p>Once you have your credentials, connect with Remote Desktop (RDP, port 3389).</p>'
                    . '{$signature}',
                'subjectPt' => 'O seu VPS Windows está pronto',
                'bodyPt' => '<p>Olá {$client_name},</p>'
                    . '<p>O seu VPS <strong>{$service_domain}</strong> foi instalado com Windows e está online.</p>'
                    . '<ul>'
                    . '<li>IP: {$service_dedicated_ip}</li>'
                    . '</ul>'
                    . '<p>Por razões de segurança, os seus dados de acesso são entregues em separado pela nossa equipa. Irá recebê-los em breve.</p>'
                    . '<p>Assim que tiver os seus dados de acesso, ligue-se por Ambiente de Trabalho Remoto (RDP, porta 3389).</p>'
                    . '{$signature}',
            ],
            [
                'name' => self::EMAIL_TEMPLATE_PWCHANGED,
                'subjectEn' => 'Your VPS password was changed',
                'bodyEn' => '<p>Hello {$client_name},</p>'
                    . '<p>The root password for your VPS <strong>{$service_product_name}</strong> was just changed.</p>'
                    . '<p>If this was not you, contact us immediately.</p>'
                    . '{$signature}',
                'subjectPt' => 'A palavra-passe do seu VPS foi alterada',
                'bodyPt' => '<p>Olá {$client_name},</p>'
                    . '<p>A palavra-passe de root do seu VPS <strong>{$service_product_name}</strong> acabou de ser alterada.</p>'
                    . '<p>Se não reconhece esta alteração, contacte-nos imediatamente.</p>'
                    . '{$signature}',
            ],
            [
                'name' => self::EMAIL_TEMPLATE_UPGRADE,
                'subjectEn' => 'Your VPS upgrade is complete',
                'bodyEn' => '<p>Hello {$client_name},</p>'
                    . '<p>Your VPS <strong>{$service_product_name}</strong> has been upgraded and is back online. Your access details are unchanged.</p>'
                    . '<div>{$product_specs}</div>'
                    . '{$signature}',
                'subjectPt' => 'O upgrade do seu VPS está concluído',
                'bodyPt' => '<p>Olá {$client_name},</p>'
                    . '<p>O seu VPS <strong>{$service_product_name}</strong> foi atualizado e está novamente online. Os seus acessos mantêm-se.</p>'
                    . '<div>{$product_specs}</div>'
                    . '{$signature}',
            ],
        ];
    }

    /**
     * Overwrite an existing template's subject+message to the current version
     * (English base row + Portuguese variant). Used by the rev-flagged migration.
     */
    private static function forceTemplate(string $name, string $subjectEn, string $messageEn, string $subjectPt, string $messagePt): void
    {
        Capsule::table('tblemailtemplates')->where('name', $name)->where('language', '')
            ->update(['subject' => $subjectEn, 'message' => $messageEn]);
        Capsule::table('tblemailtemplates')->where('name', $name)->where('language', 'portuguese')
            ->update(['subject' => $subjectPt, 'message' => $messagePt]);
    }

    /**
     * Insert one product email template (English base + Portuguese variant) when
     * it does not exist yet. Idempotent.
     */
    private static function ensureOneTemplate(string $name, string $subjectEn, string $messageEn, string $subjectPt, string $messagePt): void
    {
        if (Capsule::table('tblemailtemplates')->where('name', $name)->exists()) {
            return;
        }
        $base = [
            'name' => $name,
            'type' => 'product',
            'subject' => $subjectEn,
            'message' => $messageEn,
            'fromname' => '',
            'fromemail' => '',
            'disabled' => 0,
            'custom' => 1,
            'language' => '',
            'copyto' => '',
            'plaintext' => 0,
        ];
        Capsule::table('tblemailtemplates')->insert($base);
        Capsule::table('tblemailtemplates')->insert(array_merge($base, [
            'subject' => $subjectPt,
            'message' => $messagePt,
            'language' => 'portuguese',
        ]));
    }

    public static function getMeta(string $key, ?string $default = null): ?string
    {
        $row = Capsule::table(self::META)->where('mkey', $key)->first();
        return $row ? (string) $row->mvalue : $default;
    }

    public static function setMeta(string $key, string $value): void
    {
        $exists = Capsule::table(self::META)->where('mkey', $key)->exists();
        if ($exists) {
            Capsule::table(self::META)->where('mkey', $key)->update(['mvalue' => $value]);
            return;
        }
        Capsule::table(self::META)->insert(['mkey' => $key, 'mvalue' => $value]);
    }

    /**
     * Lightweight JSON cache on top of the META table. Returns the decoded value
     * when present and fresher than $ttlSeconds, otherwise null.
     *
     * @return mixed|null
     */
    public static function getCache(string $key, int $ttlSeconds)
    {
        $row = Capsule::table(self::META)->where('mkey', 'cache:' . $key)->first();
        if (!$row) {
            return null;
        }
        $payload = json_decode((string) $row->mvalue, true);
        if (!is_array($payload) || !array_key_exists('at', $payload) || !array_key_exists('data', $payload)) {
            return null;
        }
        if ((time() - (int) $payload['at']) > $ttlSeconds) {
            return null;
        }
        return $payload['data'];
    }

    /** @param mixed $data */
    public static function setCache(string $key, $data): void
    {
        self::setMeta('cache:' . $key, (string) json_encode(['at' => time(), 'data' => $data]));
    }

    /** Drop a cached entry so the next read re-fetches from source. Idempotent. */
    public static function forgetCache(string $key): void
    {
        Capsule::table(self::META)->where('mkey', 'cache:' . $key)->delete();
    }

    /**
     * @param list<array{datacenter:string, linux:bool, windows:bool}>|list<string> $datacenters matrix rows (or legacy codes)
     * @param mixed $raw
     */
    public static function saveAvailability(string $endpoint, string $subsidiary, string $planCode, bool $available, array $datacenters, $raw, string $checkedAt): void
    {
        $data = [
            'available' => $available ? 1 : 0,
            'datacenters_json' => json_encode($datacenters),
            'raw_json' => is_string($raw) ? $raw : json_encode($raw),
            'checked_at' => $checkedAt,
        ];
        $exists = Capsule::table(self::AVAILABILITY)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->exists();
        if ($exists) {
            Capsule::table(self::AVAILABILITY)
                ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
                ->update($data);
            return;
        }
        Capsule::table(self::AVAILABILITY)->insert(array_merge($data, [
            'endpoint' => $endpoint,
            'subsidiary' => $subsidiary,
            'plan_code' => $planCode,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getAvailability(string $endpoint, string $subsidiary, string $planCode): ?array
    {
        $row = Capsule::table(self::AVAILABILITY)
            ->where('endpoint', $endpoint)->where('subsidiary', $subsidiary)->where('plan_code', $planCode)
            ->first();
        return $row ? (array) $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getServer(int $serviceId): ?array
    {
        $row = Capsule::table(self::SERVERS)->where('service_id', $serviceId)->first();
        return $row ? (array) $row : null;
    }

    /**
     * The OS option values configured for a product (the "Operating System"
     * config dropdown), used to limit reinstall to what the plan actually sells.
     *
     * @return list<mixed>
     */
    public static function osOptionValues(int $pid): array
    {
        if ($pid <= 0) {
            return [];
        }
        return Capsule::table(self::OPTION_MAP)
            ->where('pid', $pid)
            ->where('ovh_kind', 'config')
            ->where('ovh_label', 'vps_os')
            ->pluck('whmcs_option_value')
            ->all();
    }

    /**
     * Insert or update the service<->VPS mapping row.
     *
     * @param array<string, mixed> $data
     */
    public static function upsertServer(int $serviceId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $exists = Capsule::table(self::SERVERS)->where('service_id', $serviceId)->exists();
        if ($exists) {
            Capsule::table(self::SERVERS)->where('service_id', $serviceId)->update($data);
            return;
        }
        $data['service_id'] = $serviceId;
        $data['created_at'] = date('Y-m-d H:i:s');
        Capsule::table(self::SERVERS)->insert($data);
    }

    /**
     * @param mixed $request
     * @param mixed $response
     */
    public static function logTask(?int $serviceId, string $action, $request, $response, bool $success): void
    {
        if (!Capsule::schema()->hasTable(self::TASKLOG)) {
            return;
        }
        Capsule::table(self::TASKLOG)->insert([
            'service_id' => $serviceId,
            'actor' => 'system',
            'action' => substr($action, 0, 64),
            'request' => is_string($request) ? $request : json_encode($request),
            'response' => is_string($response) ? $response : json_encode($response),
            'success' => $success ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
