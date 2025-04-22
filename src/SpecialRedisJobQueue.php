<?php

namespace MediaWiki\Extensions\SphiriaTools;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;
use Redis;
use RedisException;
use Language;
use MediaWiki\User\User;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Logger\LoggerFactory;
use Html;

class SpecialRedisJobQueue extends SpecialPage {

	public function __construct() {
		parent::__construct( 'RedisJobQueue', '', true );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		$startTime = microtime( true );

		$out = $this->getOutput();
		$user = $this->getUser();
		$lang = $this->getLanguage();
		$request = $this->getRequest();
		$this->setHeaders();
		$out->addModules( 'ext.sphiriatools.specialredisjobqueue' );
		$out->addInlineStyle('.jobqueue-stale-claimed td { background-color: #fee !important; }');
		$out->addInlineStyle('.jobqueue-data-content { display: none; }');
		$out->addInlineStyle('.jobqueue-search-highlight { background-color: yellow; font-weight: bold; }');

		list($redis, $error) = $this->connectToRedis();
		if ($error) {
			$out->addWikiMsg( 'jobqueue-redis-error', $error );
			return;
		}

		try {
			if ($request->wasPosted() && $request->getVal( 'deleteSelected' ) ) {
				if ( !$user->isAllowed( 'editinterface' ) ) {
					$out->addWikiMsg( 'badaccess-groups' );
				} elseif ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
					$out->addWikiMsg( 'sessionfailure' );
				} else {
					$this->handleDeleteRequest( $request, $user, $out, $redis );
				}
			}

			$dbName = MediaWikiServices::getInstance()->getMainConfig()->get('DBname');
			$keyPattern = "{$dbName}:jobqueue:*:l-unclaimed";
			$iterator = null;
			$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
			$jobKeys = [];

			while ($keys = $redis->scan($iterator, $keyPattern, 20)) {
				$jobKeys = array_merge($jobKeys, $keys);
				if ($iterator === 0) {
					break;
				}
			}

			while ($iterator !== 0 && $keys !== false) {
				$keys = $redis->scan($iterator, $keyPattern, 20);
				if ($keys !== false) {
					$jobKeys = array_merge($jobKeys, $keys);
				}
				if ($iterator === 0) {
					break;
				}
			}
			
			sort($jobKeys);

			if ( !$jobKeys ) {
				$out->addWikiMsg( 'jobqueue-nojobs' );
				return;
			}

			$jobDetails = [];
			$jobHData = [];
			$jobAttemptsData = [];

			$summaryHtml = '<table class="wikitable sortable">';
			$summaryHtml .= '<thead><tr>';
			$summaryHtml .= '<th>' . $this->msg( 'jobqueue-tblhdr-type' )->escaped() . '</th>';
			$summaryHtml .= '<th>' . $this->msg( 'jobqueue-tblhdr-queued' )->escaped() . '</th>';
			$summaryHtml .= '<th>' . $this->msg( 'jobqueue-tblhdr-claimed' )->escaped() . '</th>';
			$summaryHtml .= '</tr></thead><tbody>';

			foreach ( $jobKeys as $jobKey ) {
				$prefix = '{$dbName}:jobqueue:';
				$suffix = ':l-unclaimed';
				if (strpos($jobKey, $prefix) === 0 && substr($jobKey, -strlen($suffix)) === $suffix) {
					$type = substr($jobKey, strlen($prefix), -strlen($suffix));
				} else {
					$type = $jobKey;
				}

				$claimedKey = "{$dbName}:jobqueue:{$type}:z-claimed";
				$hDataKey = "{$dbName}:jobqueue:{$type}:h-data";
				$hAttemptsKey = "{$dbName}:jobqueue:{$type}:h-attempts";

				$queuedCount = $redis->lLen( $jobKey );
				if ($queuedCount === false) { $queuedCount = 0; }

				$claimedCount = $redis->zCard( $claimedKey );
				if ($claimedCount === false) { $claimedCount = 0; }

				$summaryHtml .= '<tr>';
				$summaryHtml .= '<td>' . htmlspecialchars( $type ) . '</td>';
				$summaryHtml .= '<td>' . htmlspecialchars( $queuedCount ) . '</td>';
				$summaryHtml .= '<td>' . htmlspecialchars( $claimedCount ) . '</td>';
				$summaryHtml .= '</tr>';

				$hDataResult = $redis->hGetAll($hDataKey);
				$jobHData[$type] = ($hDataResult !== false) ? $hDataResult : [];

				$hAttemptsResult = $redis->hGetAll($hAttemptsKey);
				$jobAttemptsData[$type] = ($hAttemptsResult !== false) ? $hAttemptsResult : [];

				$queuedIds = $redis->lRange($jobKey, 0, -1);
				if ($queuedIds !== false) {
					foreach($queuedIds as $id) {
						$jobDetails[] = ['id' => $id, 'type' => $type, 'status' => 'Queued', 'timestamp' => null];
					}
				}

				$claimedData = $redis->zRange($claimedKey, 0, -1, true);
				if ($claimedData !== false) {
					 foreach($claimedData as $id => $timestamp) {
						 $isQueued = false;
						 foreach ($jobDetails as $existingDetail) {
							 if ($existingDetail['id'] === $id && $existingDetail['type'] === $type && $existingDetail['status'] === 'Queued') {
								 $isQueued = true;
								 break;
							 }
						 }
						 if (!$isQueued) {
							 $jobDetails[] = ['id' => $id, 'type' => $type, 'status' => 'Claimed', 'timestamp' => $timestamp];
						 }
					}
				}
			}

			$summaryHtml .= '</tbody></table>';
			$out->addHTML( $summaryHtml );

			if ( !$user->isAllowed('editinterface') ) {
				return;
			}

			usort($jobDetails, function($a, $b) {
				if ($a['status'] === 'Claimed' && $b['status'] !== 'Claimed') {
					return -1;
				}
				if ($a['status'] !== 'Claimed' && $b['status'] === 'Claimed') {
					return 1; 
				}

				if ($a['status'] === 'Claimed' && $b['status'] === 'Claimed') {
					$tsA = $a['timestamp'] ?? PHP_INT_MAX;
					$tsB = $b['timestamp'] ?? PHP_INT_MAX;
					return $tsA <=> $tsB;
				}

				return $a['id'] <=> $b['id'];
			});

			$uniqueJobs = [];
			foreach ($jobDetails as $job) {
				$uniqueKey = $job['type'] . '|' . $job['id'];
				$uniqueJobs[$uniqueKey] = $job;
			}
			$allJobDetails = array_values($uniqueJobs);

			$jobsToDisplay = $allJobDetails;
			$totalJobs = count($jobsToDisplay);

			if ( !empty( $jobsToDisplay ) ) {
				$allTypes = [];
				foreach ( $jobsToDisplay as $job ) {
					$allTypes[$job['type']] = true;
				}

				$jobHData = [];
				$jobAttemptsData = [];

				foreach ( array_keys( $allTypes ) as $type ) {
					$hDataKey = "{$dbName}:jobqueue:{$type}:h-data";
					$hAttemptsKey = "{$dbName}:jobqueue:{$type}:h-attempts";
					$hDataResult = $redis->hGetAll( $hDataKey );
					$jobHData[$type] = ( $hDataResult !== false ) ? $hDataResult : [];
					$hAttemptsResult = $redis->hGetAll( $hAttemptsKey );
					$jobAttemptsData[$type] = ( $hAttemptsResult !== false ) ? $hAttemptsResult : [];
				}

				$this->generateDetailedTable( $out, $lang, $user, $jobsToDisplay, $jobHData, $jobAttemptsData );

			} else {
				$out->addWikiTextAsInterface( $this->msg( 'jobqueue-nodetailjobs' )->text() );
			}

		} catch ( RedisException $e ) {
			\MediaWiki\Logger\LoggerFactory::getInstance( 'SphiriaTools' )->error(
				'Redis connection/command failed for Special:JobQueue: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
			$out->addWikiMsg( 'jobqueue-redis-error', $e->getMessage() );
		} finally {
			 if (isset($redis) && $redis->isConnected()) {
				 $redis->close();
			 }
		}

		$endTime = microtime( true );
		$duration = $endTime - $startTime;
		$out->addHTML( 
			'<div style="text-align: right; font-size: smaller; color: #777; margin-top: 1em;">' . 
			htmlspecialchars( sprintf( "Page generated in %.3f seconds", $duration ) ) . 
			'</div>'
		); 
	}

	private function connectToRedis(): array {
		if ( !extension_loaded( 'redis' ) ) {
			return [null, $this->msg('jobqueue-redis-extension-missing')->text()];
		}
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$jobTypeConf = $config->get( 'JobTypeConf' );
		$defaultQueueConf = $jobTypeConf['default'] ?? null;

		if ( !$defaultQueueConf || ($defaultQueueConf['class'] ?? '') !== 'JobQueueRedis' ) {
			return [null, $this->msg('jobqueue-redis-config-missing')->text()];
		}
		$redisConf = $defaultQueueConf['redisServer'] ?? null;
		if ( !$redisConf ) {
			$redisConf = $defaultQueueConf['redisConfig']['host'] ?? null;
			if (!$redisConf) {
				return [null, $this->msg('jobqueue-redis-config-missing-server')->text()];
			}
		}

		$serverParts = explode( ':', $redisConf, 2 );
		$redisHost = $serverParts[0];
		$redisSpecificConfig = $defaultQueueConf['redisConfig'] ?? [];
		$redisPort = $redisSpecificConfig['port'] ?? $serverParts[1] ?? 6379;
		$redisPassword = $defaultQueueConf['redisPassword'] ?? $redisSpecificConfig['password'] ?? null;
		$redisDb = $defaultQueueConf['redisDatabase'] ?? $redisSpecificConfig['database'] ?? 0;
		$redisTimeout = $redisSpecificConfig['timeout'] ?? 2.5;
		$redisPersistent = $redisSpecificConfig['persistent'] ?? false;

		try {
			$redis = new Redis();
			if ( !$redis->connect( $redisHost, (int)$redisPort ) ) {
				 throw new RedisException( "Could not connect to Redis server at $redisHost:$redisPort" );
			}
			if ( $redisPassword && !$redis->auth( $redisPassword ) ) {
				throw new RedisException( "Redis authentication failed." );
			}
			if ( $redisDb && !$redis->select( (int)$redisDb ) ) {
				 throw new RedisException( "Could not select Redis database $redisDb." );
			}
			return [$redis, null];
		} catch (RedisException $e) {
			return [null, $e->getMessage()];
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'sphiria-tools';
	}

	private function handleDeleteRequest( WebRequest $request, User $user, OutputPage $out, Redis $redis ): void {
		$selectedJobs = $request->getArray('selectedJobs', []);
		if (empty($selectedJobs)) {
			$out->addWarning( $this->msg( 'jobqueue-delete-noselection' )->text() );
			return;
		}

		$dbName = MediaWikiServices::getInstance()->getMainConfig()->get('DBname');

		$deletedCount = 0;
		try {
			$pipe = $redis->pipeline();
			foreach ($selectedJobs as $jobValue) {
				$parts = explode('|', $jobValue, 3);
				if (count($parts) === 3) {
					$type = $parts[0];
					$jobId = $parts[1];
					$claimedKey = "{$dbName}:jobqueue:{$type}:z-claimed";
					$hDataKey = "{$dbName}:jobqueue:{$type}:h-data";
					$hAttemptsKey = "{$dbName}:jobqueue:{$type}:h-attempts";
					$unclaimedKey = "{$dbName}:jobqueue:{$type}:l-unclaimed";
					$pipe->zrem($claimedKey, $jobId);
					$pipe->hdel($hDataKey, $jobId);
					$pipe->hdel($hAttemptsKey, $jobId);
					$pipe->lrem($unclaimedKey, $jobId, 0);
				}
			}
			$results = $pipe->exec();

			if ( is_array( $results ) ) {
				$numJobsAttempted = count( $selectedJobs );
				$expectedResultsPerJob = 4;
				for ($i = 0; $i < $numJobsAttempted; $i++) {
					$zremIndex = $i * $expectedResultsPerJob;
					$lremIndex = $zremIndex + 3;
					$jobRemoved = false;
					if (isset($results[$zremIndex]) && $results[$zremIndex] > 0) {
						$jobRemoved = true;
						$deletedCount++;
					}
				}
			}

			$successMsg = $this->msg( 'jobqueue-delete-success', $deletedCount, count($selectedJobs) )->parse();
			$out->addHTML('<div class="mw-message-box mw-message-box-success">' . $successMsg . '</div>');

		} catch (RedisException $e) {
			\MediaWiki\Logger\LoggerFactory::getInstance( 'SphiriaTools' )->error(
				'Redis pipeline/exec failed during job deletion: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
			$out->addError( $this->msg( 'jobqueue-delete-error', $e->getMessage() )->text() );
		}
	}

	private function generateDetailedTable( OutputPage $out, Language $lang, User $user, array $jobsForCurrentPage, array $jobHData, array $jobAttemptsData ): void {

		$detailHtml = '<h2>' . $this->msg( 'jobqueue-detaillist-heading' )->escaped() . '</h2>';

		$detailHtml .= '<div style="margin-bottom: 1em;">' . 
			'<label for="jobqueue-search-input">' . $this->msg('jobqueue-search-label')->escaped() . '</label> ' . 
			Html::input( 'jobqueue-search', '', 'text', [
				'id' => 'jobqueue-search-input',
				'placeholder' => $this->msg('jobqueue-search-placeholder')->text()
			] ) . 
			'</div>';

		$detailHtml .= '<form id="jobqueue-detail-form" method="post" action="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">';

		$detailHtml .= '<table class="wikitable sortable jobqueue-detail-table">';
		$detailHtml .= '<thead><tr>';
		$detailHtml .= '<th style="width: 1em; text-align: center;"><input type="checkbox" id="jobqueue-select-all" title="' . $this->msg('jobqueue-select-all-title')->escaped() . '"></th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-id' )->escaped() . '</th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-type' )->escaped() . '</th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-status' )->escaped() . '</th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-attempts' )->escaped() . '</th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-timestamp' )->escaped() . '</th>';
		$detailHtml .= '<th>' . $this->msg( 'jobqueue-dtlhdr-data' )->escaped() . '</th>';
		$detailHtml .= '</tr></thead><tbody>';

		foreach( $jobsForCurrentPage as $detail ) {
			$jobId = $detail['id'];
			$jobType = $detail['type'];
			$jobStatus = $detail['status'];
			$jobTimestamp = $detail['timestamp'];

			$attemptCount = $jobAttemptsData[$jobType][$jobId] ?? 0;

			$rowAttrs = [];

			$formattedTimestamp = 'N/A';
			$sortableTimestamp = '';
			if ($jobStatus === 'Claimed' && $jobTimestamp !== null) {
				$formattedTimestamp = $lang->userTimeAndDate( wfTimestamp( TS_MW, $jobTimestamp ), $user );
				$sortableTimestamp = wfTimestamp( TS_ISO_8601, $jobTimestamp );

				$jobAge = time() - (int)$jobTimestamp;
				if ( $jobAge > 3600 ) {
					$rowAttrs['class'] = 'jobqueue-stale-claimed';
					$rowAttrs['title'] = $this->msg('jobqueue-stale-claimed-title')->text();
				}
			}

			$formattedData = 'N/A';
			$typeHData = $jobHData[$jobType] ?? [];
			$hDataString = $typeHData[$jobId] ?? null;

			if ( $hDataString !== null && is_string( $hDataString ) ) {
				set_error_handler(function() { /* ignore errors */ });
				try {
					$unserializedData = unserialize( $hDataString );
				} finally {
					restore_error_handler();
				}

				if ($unserializedData !== false || $hDataString === 'b:0;') {
					$dataContent = '<pre>' . htmlspecialchars( print_r( $unserializedData, true ) ) . '</pre>';
				} else {
					$dataContent = '<pre>' . htmlspecialchars( $hDataString ) . '</pre>';
					LoggerFactory::getInstance( 'SphiriaTools' )->warning(
						'Failed to unserialize job data for job ID {jobId}, type {jobType}. Displaying raw data.',
						['jobId' => $jobId, 'jobType' => $jobType]
					);
				}
				$formattedData = sprintf(
					'<button type="button" class="mw-ui-button mw-ui-quiet jobqueue-data-toggle">%s</button><div class="jobqueue-data-content">%s</div>',
					$this->msg( 'jobqueue-showhide-show' )->escaped(),
					$dataContent
				);
			}


			$rowAttrString = '';
			foreach ($rowAttrs as $attr => $val) {
				$rowAttrString .= ' ' . htmlspecialchars($attr) . '="' . htmlspecialchars($val) . '"';
			}

			$detailHtml .= '<tr' . $rowAttrString . '>';
			$checkboxValue = $jobType . '|' . $jobId . '|' . $jobStatus;
			$detailHtml .= '<td style="text-align: center;"><input type="checkbox" name="selectedJobs[]" value="' . htmlspecialchars( $checkboxValue ) . '" class="jobqueue-select-job"></td>';
			$detailHtml .= '<td data-sort-value="' . htmlspecialchars( $jobId ) . '">' . htmlspecialchars( $jobId ) . '</td>';
			$detailHtml .= '<td data-sort-value="' . htmlspecialchars( $jobType ) . '">' . htmlspecialchars( $jobType ) . '</td>';
			$detailHtml .= '<td data-sort-value="' . htmlspecialchars( $jobStatus ) . '">' . htmlspecialchars( $jobStatus ) . '</td>';
			$detailHtml .= '<td data-sort-value="' . htmlspecialchars( $attemptCount ) . '">' . htmlspecialchars( $attemptCount ) . '</td>';
			$detailHtml .= '<td data-sort-value="' . htmlspecialchars( $sortableTimestamp ) . '">' . $formattedTimestamp . '</td>';
			$detailHtml .= '<td>' . $formattedData . '</td>';
			$detailHtml .= '</tr>';
		}

		$detailHtml .= '</tbody></table>';

		$detailHtml .= '<div style="margin-top: 1em;">';
		$detailHtml .= '<div style="margin-bottom: 0.5em;">';
		$detailHtml .= '<input type="checkbox" id="confirm-delete" class="mw-ui-checkbox">';
		$detailHtml .= '<label for="confirm-delete"> ' . $this->msg('jobqueue-delete-confirm-label')->escaped() . '</label>';
		$detailHtml .= '</div>';
		$detailHtml .= '<input type="submit" id="delete-jobs-button" name="deleteSelected" value="' . $this->msg( 'jobqueue-delete-selected' )->escaped() . '" class="mw-ui-button mw-ui-destructive" disabled>';
		$detailHtml .= '</div>';
		$token = $user->getEditToken();
		$detailHtml .= '<input type="hidden" name="wpEditToken" value="' . htmlspecialchars( $token ) . '">';
		$detailHtml .= '</form>';

		$out->addHTML( $detailHtml );
	}
}