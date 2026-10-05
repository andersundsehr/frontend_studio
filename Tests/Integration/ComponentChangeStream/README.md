# Apache/FastCGI change stream regression

Run from the repository root after installing Composer and Playwright dependencies:

```bash
python3 Tests/Integration/ComponentChangeStream/probe.py
```

Requires Docker, Python 3, Node.js and Chromium.
Set `CHROMIUM_PATH` to use an installed browser;
otherwise the probe uses `/usr/bin/chromium` or Playwright's Chromium.

The probe starts isolated Apache and PHP 8.5 FPM containers with two PHP workers,
then removes its containers and network on exit.
It uses the actual stream class and TYPO3/PSR interfaces, substituting filesystem snapshots.
Apache retains its default FastCGI buffering.
FPM status uses a separate listener so capacity can be inspected during saturation.
Request lifetimes, snapshot durations and worker state are logged under `var/`.

The checks cover worker exhaustion and release after a client disconnect,
delivery before a slow initial snapshot, and disconnects during scanning.
Chromium runs the actual extension modules and EventSource with a persistent backend tree and navigating content iframe,
substituting the TYPO3 shell and fixture markup.
It checks rapid variant switching with caching enabled and disabled,
independent PHP health requests, dirty values, Save suppression, module exit,
cached restoration and owning tree removal.
The regular Node suite covers subscription filtering and lifecycle events without Docker.
