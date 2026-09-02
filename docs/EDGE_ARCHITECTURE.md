# QRKiosk Edge architecture

## Agreed operating model

- A vendor may have one active Raspberry Pi.
- The permanent customer URL is `https://coffee.tatu.co.za/shop/{vendor-slug}` in both Cloud and Edge modes.
- The permanent staff URL is `https://coffee.tatu.co.za/vendor/{vendor-slug}` in both modes.
- Public DNS sends those URLs to the hosted QRKiosk service. DNS supplied by the Pi hotspot sends the same hostname to the Pi.
- The application that serves the customer session owns the order. Cloud records Cloud orders; the Pi records Edge orders.
- Carts do not move between Cloud and Edge.
- Vendor content and menu administration remain central. The Pi downloads signed snapshots and does not provide local menu editing.
- Stock synchronization is deferred.
- When the Pi has upstream connectivity, Yoco is coordinated online. When it does not, the customer explicitly selects manual payment. Card details are never collected for later processing.

## Trust boundaries

The central service retains vendor payment and messaging secrets. A Pi receives only a revocable device credential and the content required to serve its assigned vendor. Customer devices never receive device or vendor integration credentials.

Enrollment uses a single-use key with a 30-minute expiry. Successful enrollment rotates the vendor's previous device identity and secret. Device secrets are encrypted centrally and stored with mode `0600` on the Pi.

Every snapshot is authenticated and signed. The Pi verifies the signature and assigned vendor slug before atomically replacing its local content.

## Order identity and ownership

Every future Edge order must contain:

- a globally unique `order_uuid` generated on the originating Pi;
- `origin_type=edge`;
- the immutable originating `device_id`;
- local created and updated timestamps;
- a synchronization state.

Uploading an Edge order creates or updates the central replica by `order_uuid`. Retries must be idempotent and cannot create a second sale. Cloud orders use the same identity model with `origin_type=cloud`.

## Network model

The Pi uses Ethernet or a dedicated upstream interface for central synchronization and Yoco coordination. Its Wi-Fi interface provides the isolated customer network.

The target configuration will:

- resolve `coffee.tatu.co.za` to the Pi only for hotspot clients;
- serve valid HTTPS locally;
- allow customer DNS and HTTPS access to the Pi;
- block general customer forwarding;
- allow only the external destinations required for a browser-hosted Yoco/3-D Secure flow;
- permit the Pi itself to reach central QRKiosk, Yoco, time, DNS and managed update services.

The existing prototype currently redirects HTTP to its Flask port and permits broad customer forwarding. It must not become the production firewall configuration.

## Delivery phases

1. Device enrollment, revocation and signed content snapshots.
2. Local production web service using the assigned slug and mirrored branding/menu data.
3. Split DNS, HTTPS, firewall and explicit Edge status indicator.
4. Local order creation with UUID ownership and manual-payment fallback.
5. Idempotent outbound order/status synchronization.
6. Central Yoco checkout coordination and verified payment result polling.
7. Managed updates, monitoring, backup/restore and installation-image automation.
