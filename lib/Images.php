<?php

namespace OvhVps;

/**
 * OVH reinstall-image catalogue for a VPS, with a DB-backed cache.
 *
 * /vps/{sn}/images/available returns bare ids; expanding each one to {id,name}
 * is an N+1 of sequential OVH calls (easily 10-30s on a big catalogue), far too
 * slow to run while a customer waits on the Reinstall tab. The expanded list is
 * therefore cached in the meta table and kept warm by the cron
 * ({@see Cron::refreshImageCaches}), so the client path normally serves a cache
 * hit and only rebuilds inline as a cold-start fallback.
 */
class Images
{
    /** Client reads accept a cache up to 24h old (the catalogue is stable). */
    public const TTL_SECONDS = 86400;

    /** The cron re-warms entries older than this, so the 24h TTL never lapses. */
    public const REFRESH_AGE_SECONDS = 72000; // 20h

    /** Max services (re)warmed per cron tick, to avoid OVH call bursts. */
    public const REFRESH_BATCH = 3;

    /**
     * The {id,name} list for the VPS, from cache when fresh enough, otherwise
     * fetched from OVH and cached.
     *
     * @return list<array{id:string,name:string}>
     */
    public static function cached(OvhClient $client, string $serviceName): array
    {
        $hit = Database::getCache(self::key($serviceName), self::TTL_SECONDS);
        if (is_array($hit)) {
            return $hit;
        }
        return self::warm($client, $serviceName);
    }

    /**
     * Force-refresh the cache from OVH: list the image ids, expand each to
     * {id,name} (falling back to the bare id when a detail call fails), store.
     *
     * @return list<array{id:string,name:string}>
     */
    public static function warm(OvhClient $client, string $serviceName): array
    {
        $ids = $client->get('/vps/' . $serviceName . '/images/available');
        $available = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->get('/vps/' . $serviceName . '/images/available/' . rawurlencode((string) $id));
                } catch (\Throwable $e) {
                    $detail = null;
                }
                if (is_array($detail)) {
                    $available[] = [
                        'id' => (string) ($detail['id'] ?? $id),
                        'name' => (string) ($detail['name'] ?? $detail['distribution'] ?? $id),
                    ];
                } else {
                    $available[] = ['id' => (string) $id, 'name' => (string) $id];
                }
            }
        }
        Database::setCache(self::key($serviceName), $available);
        return $available;
    }

    /** Drop the cached list (service terminated, or admin forcing a rebuild). */
    public static function forget(string $serviceName): void
    {
        Database::forgetCache(self::key($serviceName));
    }

    /** True when the cache is missing or old enough for the cron to re-warm. */
    public static function isStale(string $serviceName): bool
    {
        return Database::getCache(self::key($serviceName), self::REFRESH_AGE_SECONDS) === null;
    }

    private static function key(string $serviceName): string
    {
        return 'images:' . $serviceName;
    }
}
