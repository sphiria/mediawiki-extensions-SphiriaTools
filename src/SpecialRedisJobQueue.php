<?php

namespace MediaWiki\Extensions\SphiriaTools;

use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueRedis;
use MediaWiki\Language\Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use RedisException;
use Wikimedia\ObjectCache\RedisConnectionPool;

class SpecialRedisJobQueue extends SpecialPage {

	public function __construct() {
		parent::__construct( 'RedisJobQueue' );
	}

	public function getRestriction(): string {
		return 'read';
	}

	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$startTime = microtime( true );
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();
		$out->addModules( 'ext.sphiriatools.specialredisjobqueue' );

		[ $store, $error ] = $this->connectToRedis();
		if ( $error !== null ) {
			$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-redis-error', $error )->escaped() ) );
			return;
		}

		try {
			if ( $request->wasPosted() && $request->getVal( 'deleteSelected' ) ) {
				if ( !$user->isAllowed( 'editinterface' ) ) {
					$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-delete-permission' )->escaped() ) );
				} elseif ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
					$out->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->escaped() ) );
				} else {
					$this->checkReadOnly();
					$this->handleDeleteRequest( $request, $out, $store );
				}
			}

			$summary = $store->getSummary();
			if ( !$summary ) {
				$out->addWikiMsg( 'jobqueue-nojobs' );
				return;
			}

			$summaryHtml = '<table class="wikitable sortable"><thead><tr>';
			foreach ( [ 'type', 'queued', 'claimed' ] as $column ) {
				$summaryHtml .= Html::element( 'th', [], $this->msg( 'jobqueue-tblhdr-' . $column )->text() );
			}
			$summaryHtml .= '</tr></thead><tbody>';
			foreach ( $summary as $type => $counts ) {
				$summaryHtml .= '<tr>' . Html::element( 'td', [], $type ) .
					Html::element( 'td', [], (string)$counts['queued'] ) .
					Html::element( 'td', [], (string)$counts['claimed'] ) . '</tr>';
			}
			$out->addHTML( $summaryHtml . '</tbody></table>' );

			// Load payloads only for users who may view them.
			if ( $user->isAllowed( 'editinterface' ) ) {
				$details = $store->getDetails( array_keys( $summary ) );
				if ( $details ) {
					$this->generateDetailedTable( $out, $this->getLanguage(), $user, $details );
				} else {
					$out->addWikiMsg( 'jobqueue-nodetailjobs' );
				}
			}
		} catch ( RedisException $e ) {
			LoggerFactory::getInstance( 'SphiriaTools' )->error(
				'Redis command failed for Special:RedisJobQueue: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
			$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-redis-error', $e->getMessage() )->escaped() ) );
		} finally {
			// RedisConnRef returns the connection to core's pool when released.
			unset( $store );
			$out->addHTML( Html::element( 'div', [ 'class' => 'jobqueue-generation-time' ],
				sprintf( 'Page generated in %.3f seconds', microtime( true ) - $startTime ) ) );
		}
	}

	/** @return array{?RedisJobQueueStore,?string} */
	private function connectToRedis(): array {
		if ( !extension_loaded( 'redis' ) ) {
			return [ null, $this->msg( 'jobqueue-redis-extension-missing' )->text() ];
		}
		$conf = $this->getConfig()->get( 'JobTypeConf' )['default'] ?? [];
		$class = $conf['class'] ?? '';
		if ( !is_string( $class ) || !is_a( $class, JobQueueRedis::class, true ) ) {
			return [ null, $this->msg( 'jobqueue-redis-config-missing' )->text() ];
		}
		if ( !is_string( $conf['redisServer'] ?? null ) || $conf['redisServer'] === '' ) {
			return [ null, $this->msg( 'jobqueue-redis-config-missing-server' )->text() ];
		}
		$redisConfig = $conf['redisConfig'] ?? [];
		// Match JobQueueRedis: payloads are serialized by the queue itself.
		$redisConfig['serializer'] = 'none';
		$pool = RedisConnectionPool::singleton( $redisConfig );
		$connection = $pool->getConnection( $conf['redisServer'], LoggerFactory::getInstance( 'SphiriaTools' ) );
		if ( !$connection ) {
			return [ null, $this->msg( 'jobqueue-redis-connect-error' )->text() ];
		}
		$domain = WikiMap::getCurrentWikiDbDomain()->getId();
		return [ new RedisJobQueueStore( $connection, $domain ), null ];
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'sphiria-tools';
	}

	private function handleDeleteRequest( WebRequest $request, OutputPage $out, RedisJobQueueStore $store ): void {
		$selectedJobs = $request->getArray( 'selectedJobs', [] );
		if ( !$selectedJobs ) {
			$out->addHTML( Html::warningBox( $this->msg( 'jobqueue-delete-noselection' )->escaped() ) );
			return;
		}
		if ( !$request->getBool( 'confirmDelete' ) ) {
			$out->addHTML( Html::warningBox( $this->msg( 'jobqueue-delete-unconfirmed' )->escaped() ) );
			return;
		}

		// Validate the complete selection before deleting anything. JSON keeps job
		// types and IDs containing separator characters unambiguous.
		$jobs = [];
		$jobTypeConf = $this->getConfig()->get( 'JobTypeConf' );
		foreach ( $selectedJobs as $value ) {
			$job = is_string( $value ) ? json_decode( $value, true ) : null;
			if ( !is_array( $job ) || !array_is_list( $job ) || count( $job ) !== 2 ||
				!is_string( $job[0] ) || !is_string( $job[1] ) || $job[0] === '' || $job[1] === ''
			) {
				$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-delete-invalid' )->escaped() ) );
				return;
			}
			$queueConf = $jobTypeConf[$job[0]] ?? $jobTypeConf['default'];
			if ( ( $queueConf['readOnlyReason'] ?? false ) !== false ) {
				$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-delete-readonly' )->escaped() ) );
				return;
			}
			$jobs[json_encode( $job )] = $job;
		}

		$deleted = 0;
		try {
			foreach ( $jobs as [ $type, $id ] ) {
				$deleted += (int)$store->deleteJob( $type, $id );
			}
			$out->addHTML( Html::successBox(
				$this->msg( 'jobqueue-delete-success', $deleted, count( $jobs ) )->escaped()
			) );
		} catch ( RedisException $e ) {
			LoggerFactory::getInstance( 'SphiriaTools' )->error(
				'Redis command failed during job deletion: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
			$out->addHTML( Html::errorBox( $this->msg( 'jobqueue-delete-error', $e->getMessage() )->escaped() ) );
		}
	}

	private function generateDetailedTable( OutputPage $out, Language $lang, User $user, array $jobsForCurrentPage ): void {

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

			$attemptCount = $detail['attempts'];

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
			$hDataString = $detail['data'];

			if ( is_string( $hDataString ) ) {
				$dataContent = Html::element( 'pre', [], RedisJobQueueStore::formatData( $hDataString ) );
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
			$checkboxValue = json_encode( [ $jobType, $jobId ] );
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
		$detailHtml .= '<input type="checkbox" id="confirm-delete" name="confirmDelete" value="1" class="mw-ui-checkbox">';
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
