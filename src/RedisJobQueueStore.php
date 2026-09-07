<?php

namespace MediaWiki\Extensions\SphiriaTools;

use MediaWiki\WikiMap\WikiMap;
use Redis;
use RedisException;
use Wikimedia\ObjectCache\RedisConnRef;

/**
 * Administrative access to the MediaWiki 1.46 JobQueueRedis storage format.
 * Keep key construction and mutations in sync with core's JobQueueRedis.
 */
class RedisJobQueueStore {

	public function __construct(
		private RedisConnRef $redis,
		private string $domain
	) {
	}

	private function key( string $type, string $property ): string {
		return implode( ':', array_map( 'rawurlencode', [
			WikiMap::getWikiIdFromDbDomain( $this->domain ), 'jobqueue', $type, $property
		] ) );
	}

	/** @return array<string,array{queued:int,claimed:int}> */
	public function getSummary(): array {
		// SCAN returns physical keys, including any configured Redis prefix.
		$connectionPrefix = (string)$this->redis->getOption( Redis::OPT_PREFIX );
		$prefix = $connectionPrefix . rawurlencode( WikiMap::getWikiIdFromDbDomain( $this->domain ) ) .
			':jobqueue:';
		$patternPrefix = strtr( $prefix, [ '\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[' ] );
		$types = [];
		foreach ( [ 'l-unclaimed', 'z-claimed' ] as $property ) {
			$suffix = ':' . $property;
			$iterator = null;
			do {
				$keys = $this->redis->scan( $iterator, $patternPrefix . '*' . $suffix, 100 );
				foreach ( $keys ?: [] as $key ) {
					if ( str_starts_with( $key, $prefix ) && str_ends_with( $key, $suffix ) ) {
						$type = rawurldecode( substr( $key, strlen( $prefix ), -strlen( $suffix ) ) );
						$types[$type] = true;
					}
				}
			} while ( $iterator !== 0 );
		}

		ksort( $types );
		$summary = [];
		foreach ( array_keys( $types ) as $type ) {
			$queued = (int)$this->redis->lLen( $this->key( $type, 'l-unclaimed' ) );
			$claimed = (int)$this->redis->zCard( $this->key( $type, 'z-claimed' ) );
			if ( $queued || $claimed ) {
				$summary[$type] = [ 'queued' => $queued, 'claimed' => $claimed ];
			}
		}
		return $summary;
	}

	/** @return array[] Jobs may change between reads while workers are running. */
	public function getDetails( array $types ): array {
		$jobs = [];
		foreach ( $types as $type ) {
			$byId = [];
			foreach ( $this->redis->lRange( $this->key( $type, 'l-unclaimed' ), 0, -1 ) ?: [] as $id ) {
				$byId[$id] = [ 'id' => $id, 'type' => $type, 'status' => 'Queued', 'timestamp' => null ];
			}
			foreach ( $this->redis->zRange( $this->key( $type, 'z-claimed' ), 0, -1, true ) ?: [] as $id => $ts ) {
				$byId[$id] = [ 'id' => (string)$id, 'type' => $type, 'status' => 'Claimed', 'timestamp' => (int)$ts ];
			}
			if ( !$byId ) {
				continue;
			}
			// Batch hash reads to avoid two network round trips for every job.
			$ids = array_keys( $byId );
			$data = $this->redis->hMGet( $this->key( $type, 'h-data' ), $ids );
			$attempts = $this->redis->hMGet( $this->key( $type, 'h-attempts' ), $ids );
			foreach ( $byId as $id => $job ) {
				$job['data'] = $data[$id] ?? false;
				$job['attempts'] = (int)( $attempts[$id] ?? 0 );
				$jobs[] = $job;
			}
		}
		usort( $jobs, static function ( $a, $b ) {
			return [ $a['status'] !== 'Claimed', $a['timestamp'], $a['type'], $a['id'] ] <=>
				[ $b['status'] !== 'Claimed', $b['timestamp'], $b['type'], $b['id'] ];
		} );
		return $jobs;
	}

	/**
	 * Remove a currently queued or claimed job and its bookkeeping atomically.
	 * Leave jobs which have since moved to delayed/abandoned queues alone.
	 */
	public function deleteJob( string $type, string $id ): bool {
		$script = <<<'LUA'
local unclaimed, claimed, data, attempts, sha1ById, idBySha1, delayed, abandoned, queues = unpack(KEYS)
local id, queueName = unpack(ARGV)
if redis.call('zScore', delayed, id) or redis.call('zScore', abandoned, id) then
	return 0
end
local removed = redis.call('lRem', unclaimed, 0, id) + redis.call('zRem', claimed, id)
if removed == 0 then
	return 0
end
local sha1 = redis.call('hGet', sha1ById, id)
if sha1 and redis.call('hGet', idBySha1, sha1) == id then
	redis.call('hDel', idBySha1, sha1)
end
redis.call('hDel', sha1ById, id)
redis.call('hDel', data, id)
redis.call('hDel', attempts, id)
if redis.call('lLen', unclaimed) == 0 and redis.call('zCard', claimed) == 0
	and redis.call('zCard', delayed) == 0 then
	redis.call('sRem', queues, queueName)
end
return 1
LUA;
		$keys = [];
		foreach ( [ 'l-unclaimed', 'z-claimed', 'h-data', 'h-attempts', 'h-sha1ById',
			'h-idBySha1', 'z-delayed', 'z-abandoned' ] as $property
		) {
			$keys[] = $this->key( $type, $property );
		}
		$keys[] = 'global:jobqueue:s-queuesWithJobs';
		$result = $this->redis->luaEval( $script, [
			...$keys, $id, json_encode( [ $type, $this->domain ] )
		], count( $keys ) );
		if ( $result === false ) {
			throw new RedisException( $this->redis->getLastError() ?: 'Job deletion failed.' );
		}
		return $result === 1;
	}

	/** Decode core's optional gzip envelope without instantiating arbitrary classes. */
	public static function formatData( string $blob ): string {
		$data = @unserialize( $blob, [ 'allowed_classes' => [ \stdClass::class ] ] );
		if ( $data instanceof \stdClass && ( $data->enc ?? null ) === 'gzip' &&
			is_string( $data->blob ?? null ) && function_exists( 'gzinflate' )
		) {
			$inflated = @gzinflate( $data->blob );
			if ( $inflated !== false ) {
				$data = @unserialize( $inflated, [ 'allowed_classes' => false ] );
			}
		}
		return is_array( $data ) ? print_r( $data, true ) : $blob;
	}
}
