# Google Books Integration for OMP 0.1.2.16

Release 0.1.2.16 fixes an error that made OMP answer HTTP 500 after saving a published book.

## What happened

With automatic discovery or automatic synchronization enabled, the plugin reacts to three native events of a published monograph: its publication, a change to its metadata, and a change to its public proof files. In all three it asked Laravel to run a background job "after the response", through `dispatchAfterResponse()`.

That Laravel feature registers a callback with `terminating()`, a method of the Laravel application class. OMP's service container is not a Laravel application and has no such method, so the call raised:

`Call to undefined method PKP\core\PKPContainer::terminating()`

The error was raised after OMP had already stored the editor's change. The editor, or any tool using the OMP API, received HTTP 500 for a save that had in fact succeeded, and the Google Books job was never queued.

## What changes

- The three events now queue their job through a shutdown function, when the request has finished its own work. The guarantee the plugin needs is kept: the job is created only after OMP has committed the record that triggered it.
- If a job cannot be queued, the failure is written to the PHP error log and the editor's request is not interrupted.
- No setting, table or stored record changes. No database migration.

## Why the tests did not catch it

The test doubles implemented `dispatchAfterResponse()` as a harmless method. They now fail exactly as OMP does, and the suite rejects any use of after-response dispatch in the plugin sources.

## Upgrade

Upload 0.1.2.16 as an in-place plugin upgrade. Do not uninstall the previous release.
