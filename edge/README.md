# QRKiosk Edge

This directory contains the Raspberry Pi edge runtime. Phase one securely enrolls one Pi for one vendor and mirrors a signed vendor/menu snapshot into local SQLite. It does not replace the existing prototype services yet.

## Security model

- A super-admin generates a single-use enrollment key that expires after 30 minutes.
- Enrollment rotates the vendor's previous Edge device credentials.
- The Pi receives a random device identifier and secret. The secret is stored mode `0600` locally and encrypted at rest centrally.
- Snapshot requests use bearer authentication and every response is signed with the device secret.
- Yoco and messaging credentials are never included in a snapshot.
- A revoked device cannot synchronize.

## Pi commands

Install `agent.py` and `schema.sql` under `/opt/qrkiosk-edge`, owned by root. Create a locked-down `qrkiosk-edge` system user and `/var/lib/qrkiosk-edge` state directory.

Enroll interactively so the one-time key is not saved in shell history:

```bash
sudo -u qrkiosk-edge /opt/qrkiosk-edge/agent.py enroll --server https://coffee.tatu.co.za
```

For managed installation, pass a mode `0600` file with `--key-file`; it is removed automatically only after successful enrollment.

Synchronize and inspect status:

```bash
sudo -u qrkiosk-edge /opt/qrkiosk-edge/agent.py sync
sudo -u qrkiosk-edge /opt/qrkiosk-edge/agent.py status
```

The included systemd timer runs synchronization every minute. `qrkiosk-edge-web.service` serves the mirrored vendor at `/shop/{vendor-slug}` using Gunicorn. Local order creation, outbound order synchronization, split DNS, HTTPS, and the restricted hotspot firewall are subsequent phases.
