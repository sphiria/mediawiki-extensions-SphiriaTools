# SphiriaTools MediaWiki Extension

Provides utility tools under the "Sphiria tools" category on Special:SpecialPages.

## Features

### Special:RedisJobQueue

This special page provides an interface to view and manage jobs in a Redis-backed MediaWiki job queue. It requires the `default` job queue in `$wgJobTypeConf` to be configured to use `JobQueueRedis`.

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
    *   Removes selected jobs from all relevant Redis lists and hashes (`l-unclaimed`, `z-claimed`, `h-data`, `h-attempts`).
*   **Performance:** Includes page generation time at the bottom.

## Installation

1.  Ensure the `php-redis` extension is installed and enabled on your server.
2.  Download the extension files and place them in a directory named `SphiriaTools` within your MediaWiki `extensions/` folder.
3.  Add the following line to your `LocalSettings.php` file:
    ```php
    wfLoadExtension( 'SphiriaTools' );
    ```
5.  Run the MediaWiki update script:
    ```bash
    php maintenance/update.php --quick
    ```
6.  Navigate to Special:Version on your wiki to verify the extension is installed correctly.
7.  You should now see the "Sphiria tools" category and the "Redis job queue" page listed on Special:SpecialPages. Users with `editinterface` permission will see the detailed view and management options.