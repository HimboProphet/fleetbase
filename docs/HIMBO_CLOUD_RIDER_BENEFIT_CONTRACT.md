# HIMBO.CLOUD Active Rider Benefit Contract

Status: source contract approved for implementation; live storefront redemption is not enabled.

Product decision, 2026-07-13:

- Entitlement: `himbo_express_active_rider`
- Holder: the Rider's RHINO ID account
- Benefit: 10% off products in the HIMBO Express line on HIMBO.CLOUD
- Shipping: free shipping on eligible HIMBO Express-line orders
- Coupon model: no public or shareable coupon code
- Activation: only after HIMBO Express approves and activates the Rider
- Revocation: immediate when active Rider status is stopped, suspended, closed, or revoked

## Redemption Boundary

HIMBO.CLOUD must verify the RHINO ID entitlement at checkout. If entitlement verification is unavailable, stale, malformed, or negative, the discount and shipping benefit fail closed. A cached positive result must have a short bounded lifetime and cannot outlive a Rider deactivation event.

The storefront may receive only the minimum decision data needed to redeem the benefit:

- RHINO ID subject identifier
- entitlement name
- entitlement status
- issued and expiry timestamps
- signed decision or bounded server-to-server verification result

The storefront must not receive identity-document images, selfies, SSNs, Checkr reports, or background-screen details.

## Commerce Hook

The live HIMBO.CLOUD WordPress surface does not currently expose an approved WooCommerce or storefront entitlement integration in this workspace. Do not substitute a reusable discount code or client-side trust flag. Implement redemption only after the canonical commerce source, checkout system, and RHINO ID verification hook are identified and owner-approved.

## Release Gates

- Define eligible HIMBO Express-line product IDs or taxonomy.
- Define shipping regions and any lawful exclusions in customer-facing benefit terms.
- Verify the entitlement server-side before order totals are finalized.
- Test activation, redemption, expiration, STOP/revocation, refund, and checkout retry behavior.
- Complete tax, discount, shipping, privacy, and consumer-terms review.
- Publish only with explicit owner approval and before/after production smoke verification.
