# This page moved: [`INSTALL.md`](INSTALL.md)

The reporter's install runbook was this file until card#12571, which replaced its bash blocks with one node
installer, [`install.js`](install.js), for Linux, Windows and macOS. [`INSTALL.md`](INSTALL.md) is now the reference
for what each step does on each OS — the Linux crontab supervision is its
[§ Linux: the user crontab](INSTALL.md#linux-the-user-crontab), the Windows install its
[§ Windows: no supervisor](INSTALL.md#windows-no-supervisor) and [§ Windows: what card#12335 learned](INSTALL.md#windows-what-card12335-learned),
macOS its [§ macOS: a user launchd agent](INSTALL.md#macos-a-user-launchd-agent), and the upgrade its
[§ Upgrading an existing seat](INSTALL.md#upgrading-an-existing-seat). The file keeps this name because the crontab
block every Linux seat carries names it, and the README, the changelog and card records cite it; the runbook as it
stood is in this file's history (`git log --follow fleet-reporter/INSTALL.md`).
