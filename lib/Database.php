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

    /** Custom WHMCS email template the cron sends when a plain VPS is ready. */
    public const EMAIL_TEMPLATE = 'OVH VPS Access Ready';

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
                $t->string('product_type', 32)->default('vps');
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
                $t->string('n8n_url')->nullable();
                $t->string('n8n_user')->nullable();
                $t->string('n8n_state', 32)->nullable();
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
     * plus a Portuguese variant. The cron sends it when a plain VPS is ready.
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
        if (Capsule::table('tblemailtemplates')->where('name', self::EMAIL_TEMPLATE)->exists()) {
            return;
        }

        $en = '<p>Hello {$client_name},</p>'
            . '<p>Your VPS <strong>{$service_domain}</strong> is ready.</p>'
            . '<ul>'
            . '<li>IP: {$service_dedicated_ip}</li>'
            . '<li>Username: {$service_username}</li>'
            . '<li>Password: {$service_password}</li>'
            . '</ul>'
            . '<p>Open the Console tab in your client area and log in, or connect over SSH: '
            . '<code>ssh {$service_username}@{$service_dedicated_ip}</code></p>';

        $pt = '<p>Olá {$client_name},</p>'
            . '<p>O seu VPS <strong>{$service_domain}</strong> está pronto.</p>'
            . '<ul>'
            . '<li>IP: {$service_dedicated_ip}</li>'
            . '<li>Utilizador: {$service_username}</li>'
            . '<li>Palavra-passe: {$service_password}</li>'
            . '</ul>'
            . '<p>Abra o separador Consola na sua área de cliente e inicie sessão, ou ligue por SSH: '
            . '<code>ssh {$service_username}@{$service_dedicated_ip}</code></p>';

        $base = [
            'name' => self::EMAIL_TEMPLATE,
            'type' => 'product',
            'subject' => 'Your VPS is ready',
            'message' => $en,
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
            'subject' => 'O seu VPS está pronto',
            'message' => $pt,
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
