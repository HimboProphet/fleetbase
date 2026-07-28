# HIMBO EXPRESS Prime-Time Runbook

Status: MVP private dispatch lane promoted 2026-06-28; insulated public shell and courier intake deployed 2026-07-27; self-hosted routing deployed 2026-07-28.

Product decision, 2026-06-29: Fleetbase is the operational backbone for HIMBO EXPRESS, and HIMBO EXPRESS is expected to be at least partly public-facing. Treat Fleetbase licensing/compliance as a release gate before external users or customers interact with the modified Fleetbase-backed service.

Priority 1 insulation decision, 2026-06-29: public HIMBO EXPRESS surfaces must not expose the Fleetbase console as the default customer experience. The apex `https://himbo.express/` serves a HIMBO public shell. Fleetbase is internal ops only and may be reached only through RHINO ID handoff plus explicit ops/runtime paths until `ops.himbo.express` DNS and origin TLS are provisioned.

Fallback brand/domain note, 2026-06-29: Travis also owns `rhino.express` as a neutral fallback express brand if the public rejects the HIMBO name. Treat `rhino.express` as a reserved future public-facing brand lane for the same insulated Fleetbase Ops backbone; do not point it directly at the Fleetbase console.

This document defines the release gates before HIMBO EXPRESS can move from the private Travis-only MVP lane to public or multi-operator prime time.

## Current Supported Mode

- Entry: RHINO ID Lounge `/launch/himbo-express`
- Operator scope: exact email allowlist only
- Current operator: `travis@2rhino.com`
- Dispatch user mapping: `admin@himbo.express`
- Public apex: HIMBO public shell, not Fleetbase console
- Internal ops: Fleetbase console behind RHINO ID handoff and explicit console/runtime routes
- Courier enrollment: Verified RHINO ID-gated public intake at `/couriers/apply`, backed by `/int/v1/couriers/enrollments`
- Public/multi-operator courier rollout: not approved until every gate below passes

## Courier Enrollment Mode

Implemented 2026-07-10 as an insulated public HIMBO Express surface, not a Fleetbase console path. Deployed to WORLDENGINE on 2026-07-27: `https://himbo.express/` serves the HIMBO public shell, `https://himbo.express/couriers/apply/` serves the courier intake page, `/login` redirects through RHINO ID, `/auth/rhino-id/health` returns healthy Fleetbase bridge JSON, and `/ops` redirects to `/console`.

- Candidate entry: `https://himbo.express/couriers/apply`
- RHINO ID handoff: `https://id.2rhino.com/login?next=%2Flaunch%2Fhimbo-courier`
- RHINO ID launch route: `/launch/himbo-courier`
- Intake API: `POST /int/v1/couriers/enrollments`
- Storage: append-only JSONL at `api/storage/app/himbo-express/courier-enrollments.jsonl`
- Dispatch/admin access remains separate: `/launch/himbo-express` and `/auth/rhino-id/exchange` still enforce the exact-email operator allowlist.

Courier enrollment facts:

- Couriers are independent contractors, not employees.
- Couriers own and operate their own courier business.
- HIMBO Riders require a Verified RHINO ID account.
- Verified RHINO ID requires government-issued photo ID and face scan through RHINO ID vendor verification.
- Verified status appears as a profile badge and as `Verified RHINO ID` on the RHINO ID wallet pass.
- Required vehicle: electric bike or e-scooter.
- Background check is required before account activation.
- Verification and background-screening provider charges are passed through to the Rider at actual cost with zero HIMBO/RHINO activation markup.
- A $100 refundable Courier Protection Reserve is collected only after Rider approval; it is not an activation fee or revenue.
- The reserve backs documented lost, stolen, or damaged delivery claims up to $100. Notice and an appeal path are required before applying it.
- After account closure, the unused reserve is returned after documented claims are resolved. If a claim uses the reserve, new routes pause until it is restored.
- Proof of appropriate liability and delivery-use insurance is required before activation. Final coverage wording and minimum limits remain a legal/broker release gate.
- Active Riders receive a RHINO ID-bound benefit: 10% off the HIMBO Express line on HIMBO.CLOUD plus free shipping.
- The Rider benefit must fail closed when active entitlement cannot be verified and must end when Rider status is deactivated.
- Wilton Manors delivery price is $10.
- Courier receives $5 per Wilton Manors delivery.
- HIMBO EXPRESS retains $5 per Wilton Manors delivery.
- Courier receives 100% of tips.

Release note: public wording for the HIPAA courier lane, verified identity vendor flow, background check vendor flow, reserve collection/refund/claim handling, contractor agreement, insurance language, benefit terms, and tax/payment handling requires legal/compliance review before live public recruitment.

Deployment note, 2026-07-27: `/opt/himbo-express/secrets/app.env` now contains `HIMBO_MYSQL_ROOT_PASSWORD` and `HIMBO_OSRM_HOST` for the hardened compose contract. The first public-shell deployment preserved the previously running public OSRM demo value. Backups: `/root/2rhino-backups/himbo-express-app-env-pre-contract-20260727T170524Z.env` and `/root/2rhino-backups/himbo-express-nginx-pre-public-shell-20260727T170655Z.conf`.

Routing deployment note, 2026-07-28: HIMBO Express now uses a private self-hosted OSRM service instead of the public demo. The production compose file has a `routing` service using `osrm/osrm-backend:v5.25.0`; `/opt/himbo-express/routing/himbo-florida.osrm*` was prepared from Geofabrik `florida-latest.osm.pbf`; `/opt/himbo-express/secrets/app.env` is set to `HIMBO_OSRM_HOST=http://routing:5000`; and the application container was recreated with that env. Live proof: `current-routing-1` is up with no host port exposure, `current-application-1` env reports self-hosted routing, and an internal Wilton Manors route returned OSRM `code:"Ok"`. Backups: `/root/2rhino-backups/himbo-express-app-env-pre-selfhosted-osrm-20260728T150446Z.env`, `/root/2rhino-backups/himbo-express-pre-dispatch-20260728T150454Z.tar.gz`, `/root/2rhino-backups/himbo-express-pre-dispatch-20260728T150519Z.tar.gz`, and `/root/2rhino-backups/himbo-express-pre-dispatch-20260728T150631Z.tar.gz`.

## Gate 1: Source Control And Tests

Required before expansion:

- Commit the HIMBO overlay files in `/Users/rhino/Projects/fleetbase`.
- Keep upstream Fleetbase changes separate from HIMBO overlay changes where possible.
- Backend tests must pass for:
  - missing RHINO ID session
  - invalid RHINO ID session
  - denied non-allowlisted operator
  - admin-only permissions matrix
- Add an integration smoke for the happy path against the deployed service:
  - RHINO ID launch route returns the handoff
  - Fleetbase exchange grants a bounded token only for the allowed operator
  - `/admin/permissions-matrix` renders for the mapped admin user

## Gate 1A: Fleetbase Licensing And Source Compliance

Fleetbase is AGPL-3.0-or-later in this repository. A public-facing modified Fleetbase deployment must choose a compliance lane before launch:

- AGPL lane: publish the corresponding source for HIMBO EXPRESS Fleetbase modifications under AGPL-3.0-or-later, preserve notices, document changed files, and provide a clear source-offer/download path for network users.
- Commercial lane: obtain Fleetbase commercial licensing before keeping modifications proprietary in a public-facing or SaaS-style deployment.
- Isolation lane: keep Fleetbase unmodified/internal-only and put proprietary HIMBO EXPRESS logic in a separate service that talks to Fleetbase over stable APIs. This still needs legal review before relying on it for public launch.

Do not launch public or multi-operator HIMBO EXPRESS on modified private Fleetbase code until one lane above is explicitly chosen.

Preferred current lane: isolation. Fleetbase remains the internal operations backbone; public HIMBO pages/order intake are separate and cushioned in front of Fleetbase.

## Gate 2: Production Configuration

Required before expansion:

- `HIMBO_MYSQL_ROOT_PASSWORD` is set in `/opt/himbo-express/secrets/app.env`.
- `HIMBO_OSRM_HOST` points to the private self-hosted compose service at `http://routing:5000`, backed by `/opt/himbo-express/routing/himbo-florida.osrm`.
- `HIMBO_MAIL_MAILER` is set to a real transactional mailer before customer/operator notifications depend on email.
- `RHINO_ID_REQUIRE_EXACT_EMAILS=true` remains set unless Travis explicitly approves domain-level expansion.
- `RHINO_ID_FLEETBASE_TOKEN_TTL_MINUTES` remains bounded.
- Console is built with `ENVIRONMENT=production`.

## Gate 3: Auth And Permissions Boundary

Required before expansion:

- Operator expansion is by exact email allowlist, not broad domain membership.
- Each operator has a named RHINO ID identity and a mapped Fleetbase role.
- The permissions matrix shows:
  - allowlist mode
  - allowed operators
  - mapped dispatch user
  - token TTL
  - local password posture
- Local Fleetbase password login must be either disabled for normal dispatch or documented as break-glass only with an owner-approved rotation and audit procedure.
- RHINO ID exchange logs must record denied/granted attempts without logging session tokens.

## Gate 4: Stop Check

HIMBO EXPRESS must not go public until dispatch operations can pass Principle 0.

Required proof:

- STOP/cancel mutates authoritative order/dispatch desired state.
- Queued dispatch jobs cannot execute after STOP.
- Retries cannot execute after STOP.
- Recovery logic cannot reassert stale dispatch state after STOP.
- The operator dashboard can distinguish:
  - command received
  - stop enforced
  - stop verified
  - stop violated
- A failed STOP is logged and visible in operator review.

Any YES answer to the Stop Check blocks public release.

## Gate 5: Business Operations

Required before public launch:

- Order intake flow is defined.
- Pricing/quote rules are defined.
- Payment, refund, and cancellation paths are defined.
- Protection-reserve collection, claim, appeal, restoration, and refund paths are defined and tested.
- Customer notification channels are defined and tested.
- Courier/operator onboarding and role boundaries are defined.
- Courier contractor agreement, background check consent, protection-reserve acknowledgement, insurance wording, Rider-benefit terms, and payout terms are reviewed.
- Support escalation and incident response are defined.
- Terms, privacy, and courier-specific risk disclosures are reviewed before public customer traffic.

## Gate 6: Observability And Recovery

Required before expansion:

- Public health checks cover RHINO ID, Fleetbase API, console, socket, queue, scheduler, database, and nginx.
- PULSE or equivalent monitoring alerts on failed health, queue failure, scheduler failure, high 5xx rate, and auth denial spikes.
- Backup and restore are both tested.
- Rollback command/path is documented.
- Failed jobs and dispatch errors are visible without SSH spelunking.
- Logs have retention and do not contain RHINO ID session tokens or secrets.

## Prime-Time Definition

Prime time means:

- A real operator can sign in through RHINO ID.
- A real customer/order flow can be created and tracked.
- Dispatch can be stopped safely.
- Payment/notification/support paths are known.
- The system can be monitored, backed up, restored, and rolled back.
- Expansion beyond Travis is controlled by exact identity, not vibes.
