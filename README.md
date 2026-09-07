# SphiriaTools MediaWiki Extension

Provides utility tools under the "Sphiria tools" category on Special:SpecialPages.

## Features

### Special:RedisJobQueue

This special page provides an interface to view and manage jobs in a Redis-backed MediaWiki job queue. It requires MediaWiki 1.46 or later, PHP 8.3 or later, and the PHP Redis extension. The `default` entry in `$wgJobTypeConf` must use `MediaWiki\JobQueue\JobQueueRedis` (the legacy `JobQueueRedis` alias is also accepted).

The page shows queued and claimed jobs for the current wiki on the default queue's Redis server. Job types stored on other Redis servers are outside this view. Queues containing only claimed jobs are included; delayed and abandoned jobs are not listed.

*   **Visibility:** The page is visible to all users by default (`read` permission).
*   **Summary View:** Displays a summary table showing the number of queued and claimed jobs for each job type found in the queue.
*   **Detailed View (Requires `editinterface` permission):**
    *   Displays a detailed, sortable table listing all individual jobs (both queued and claimed).
    *   Columns include: Select Checkbox, Job ID, Type, Status, Attempts, Claimed Timestamp, and Job Data.
    *   Highlights jobs claimed more than an hour ago.
    *   Job Data for each job can be viewed by clicking a "Show Data" button (data is unserialized if possible, otherwise shown raw).
    *   A search input allows dynamically filtering the detailed list by searching within the Job Data content (case-insensitive, highlights matches).
*   **Job Deletion (Requires `editinterface` permission):**
    *   Allows selecting multiple jobs via checkboxes in the detailed list.
    *   Provides a "Delete Selected Jobs" button with a confirmation checkbox.
    *   Requires a valid edit token and server-side confirmation, and respects the wiki's read-only mode.
    *   Atomically removes each selected job's queue entry, payload, attempts, and deduplication records. Empty queues are removed from the active-queue index.
    *   Jobs already completed or moved to delayed/abandoned queues are skipped. Deleting a claimed job does not stop a worker that is already executing it.
*   **Performance:** Includes page generation time at the bottom.

## Installation

1.  Ensure the `php-redis` extension is installed and enabled on your server.
2.  Download the extension files and place them in a directory named `SphiriaTools` within your MediaWiki `extensions/` folder.
3.  Add the following line to your `LocalSettings.php` file:
    ```php
    wfLoadExtension( 'SphiriaTools' );
    ```
4.  Navigate to Special:Version to verify the extension is loaded. This extension has no database schema and does not require an update script.
5.  Open Special:RedisJobQueue. Users with `editinterface` permission will see the detailed view and management options.

The extension uses MediaWiki's Redis connection pool and the same `redisServer` and `redisConfig` options as the job queue, including authentication, timeouts, connection prefixes, and socket addresses. Queue keys account for the wiki's database domain and table prefix. Core's optionally compressed payloads are decoded for display.

An existing Redis queue configuration can use:

```php
$wgJobTypeConf['default'] = [
    'class' => MediaWiki\JobQueue\JobQueueRedis::class,
    'redisServer' => 'redis:6379',
    'redisConfig' => [
        'connectTimeout' => 1,
        'readTimeout' => 2,
        // 'password' => 'your Redis password',
    ],
    'daemonized' => true,
];
```
