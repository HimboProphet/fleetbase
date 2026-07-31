<?php

namespace App\Providers;

use Fleetbase\Models\User;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        RateLimiter::for(
            'himbo-rhino-id-exchange',
            fn (Request $request) => Limit::perMinute((int) env('RHINO_ID_EXCHANGE_RATE_LIMIT_PER_MINUTE', 12))->by($request->ip())
        );

        $this->routes(
            function () {
                Route::get(
                    '/health',
                    function (Request $request) {
                        return response()->json(
                            [
                                'status' => 'ok',
                                'time' => microtime(true) - $request->attributes->get('request_start_time')
                            ]
                        );
                    }
                );

                Route::get(
                    '/auth/rhino-id/health',
                    function () {
                        return response()->json(
                            [
                                'status' => 'ok',
                                'provider' => 'rhino-id',
                                'app' => env('APP_NAME', 'HIMBO EXPRESS'),
                                'exchange' => 'enabled'
                            ]
                        );
                    }
                );

                Route::post(
                    '/auth/rhino-id/exchange',
                    function (Request $request) {
                        return $this->exchangeRhinoIdSession($request);
                    }
                )->middleware(['throttle:himbo-rhino-id-exchange']);

                Route::post(
                    '/int/v1/couriers/enrollments',
                    function (Request $request) {
                        return $this->submitCourierEnrollment($request);
                    }
                )->middleware(['throttle:himbo-rhino-id-exchange']);

                Route::get(
                    '/int/v1/auth/rhino-id/permissions-matrix',
                    function (Request $request) {
                        $user = $request->user('sanctum') ?: $request->user();
                        if (!$user) {
                            return response()->json(['error' => 'unauthenticated'], 401);
                        }

                        if (!data_get($user, 'is_admin')) {
                            return response()->json(['error' => 'admin_required'], 403);
                        }

                        return response()->json($this->rhinoIdPermissionsMatrix($request))
                            ->header('Cache-Control', 'no-store');
                    }
                );

                Route::get(
                    '/auth/rhino-id',
                    function (Request $request) {
                        $consoleUrl = rtrim((string) env('CONSOLE_HOST', 'http://localhost:4200'), '/');
                        $consoleRhinoIdHandoffPath = '/' . ltrim((string) env('CONSOLE_RHINO_ID_HANDOFF_PATH', '/auth/rhino-id'), '/');
                        $from = e((string) $request->query('from', 'rhino-id'));
                        $safeConsoleUrl = e($consoleUrl);
                        $safeConsoleRhinoIdHandoffPath = e($consoleRhinoIdHandoffPath);

                        return response(
                            <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>HIMBO EXPRESS | RHINO ID</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #030405;
      --panel: rgba(8, 10, 12, 0.72);
      --line: rgba(255, 255, 255, 0.2);
      --ink: #f4f7f8;
      --muted: rgba(244, 247, 248, 0.72);
      --accent: #13d19a;
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", sans-serif;
    }
    * { box-sizing: border-box; }
    body {
      min-height: 100vh;
      margin: 0;
      display: grid;
      place-items: center;
      padding: 24px;
      background:
        radial-gradient(circle at 50% 18%, rgba(19, 209, 154, 0.18), transparent 34%),
        var(--bg);
      color: var(--ink);
    }
    main {
      width: min(520px, 100%);
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--panel);
      padding: 32px;
      box-shadow: 0 28px 90px rgba(0, 0, 0, 0.48);
    }
    .eyebrow {
      margin: 0 0 14px;
      color: var(--accent);
      font-size: 0.76rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    h1 {
      margin: 0;
      font-size: clamp(2rem, 6vw, 3rem);
      line-height: 1;
      font-weight: 650;
      letter-spacing: 0;
    }
    p {
      margin: 18px 0 0;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.55;
    }
    a {
      display: inline-flex;
      align-items: center;
      min-height: 42px;
      margin-top: 26px;
      padding: 0 16px;
      border: 1px solid rgba(255, 255, 255, 0.32);
      border-radius: 6px;
      color: var(--ink);
      text-decoration: none;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    a:hover { background: rgba(255, 255, 255, 0.12); }
    .status { font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>
  <main>
    <p class="eyebrow">RHINO ID bridge / {$from}</p>
    <h1>HIMBO EXPRESS dispatch access</h1>
    <p class="status" id="status">Checking RHINO ID handoff...</p>
    <p>This dispatch console accepts RHINO ID operators only. No local Fleetbase password is required for this handoff.</p>
    <a href="{$safeConsoleUrl}{$safeConsoleRhinoIdHandoffPath}" id="continue">Open dispatch console</a>
  </main>
  <script>
    (() => {
      const params = new URLSearchParams(window.location.hash.slice(1));
      const token = params.get("rhino_id_session");
      const status = document.getElementById("status");
      const link = document.getElementById("continue");
      if (!token) {
        status.textContent = "No RHINO ID session fragment was received. Return to the lounge and launch again.";
        link.href = "https://id.2rhino.com/login?next=%2Flaunch%2Fhimbo-express";
        link.textContent = "Return to RHINO ID";
        return;
      }

      window.sessionStorage.setItem("himbo_express.rhino_id_session", token);
      link.style.pointerEvents = "none";
      link.setAttribute("aria-disabled", "true");
      link.textContent = "Exchanging RHINO ID session...";

      fetch("/auth/rhino-id/exchange", {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ rhino_id_session: token })
      })
        .then(async (response) => {
          const body = await response.json().catch(() => ({}));
          if (!response.ok) {
            throw new Error(body.error || "RHINO ID exchange failed.");
          }
          return body;
        })
        .then((body) => {
          const consoleHandoff = "{$safeConsoleUrl}{$safeConsoleRhinoIdHandoffPath}#authToken=" + encodeURIComponent(body.token) + "&identity=" + encodeURIComponent(body.identity);
          status.textContent = "RHINO ID verified. Opening HIMBO EXPRESS dispatch...";
          link.href = consoleHandoff;
          link.textContent = "Continue to dispatch";
          link.style.pointerEvents = "";
          link.removeAttribute("aria-disabled");
          window.location.replace(consoleHandoff);
        })
        .catch((error) => {
          status.textContent = error.message || "RHINO ID exchange failed.";
          link.href = "https://id.2rhino.com/login?next=%2Flaunch%2Fhimbo-express";
          link.textContent = "Return to RHINO ID";
          link.style.pointerEvents = "";
          link.removeAttribute("aria-disabled");
        });
    })();
  </script>
</body>
</html>
HTML
                        )->header('Cache-Control', 'no-store');
                    }
                );
            }
        );
    }

    private function exchangeRhinoIdSession(Request $request)
    {
        $sessionToken = trim((string) $request->input('rhino_id_session', ''));
        if ($sessionToken === '') {
            Log::warning('himbo_express.rhino_id_exchange_missing_session', [
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 180),
            ]);

            return response()->json(['error' => 'missing_rhino_id_session'], 400);
        }

        $rhinoIdBaseUrl = rtrim((string) env('RHINO_ID_BASE_URL', 'https://id.2rhino.com'), '/');
        $rhinoIdResponse = Http::acceptJson()
            ->withToken($sessionToken)
            ->timeout(8)
            ->get($rhinoIdBaseUrl . '/me');

        if (!$rhinoIdResponse->successful()) {
            Log::warning('himbo_express.rhino_id_exchange_invalid_session', [
                'ip' => $request->ip(),
                'status' => $rhinoIdResponse->status(),
            ]);

            return response()->json(['error' => 'invalid_rhino_id_session'], 401);
        }

        $rhinoIdUser = $rhinoIdResponse->json();
        $rhinoIdEmail = strtolower((string) data_get($rhinoIdUser, 'email', ''));
        if ($rhinoIdEmail === '' || !$this->rhinoIdEmailIsAllowed($rhinoIdEmail)) {
            Log::warning('himbo_express.rhino_id_exchange_operator_denied', [
                'ip' => $request->ip(),
                'rhino_id_email' => $rhinoIdEmail,
            ]);

            return response()->json(['error' => 'rhino_id_operator_not_allowed'], 403);
        }

        $dispatchEmail = strtolower((string) env('HIMBO_EXPRESS_DISPATCH_USER_EMAIL', env('RHINO_ID_FLEETBASE_USER_EMAIL', 'admin@himbo.express')));
        $dispatchUser = User::where('email', $dispatchEmail)->first();
        if (!$dispatchUser) {
            return response()->json(['error' => 'himbo_express_dispatch_user_missing'], 503);
        }

        if ((string) $dispatchUser->status !== 'active') {
            return response()->json(['error' => 'himbo_express_dispatch_user_inactive'], 403);
        }

        $tokenTtlMinutes = max(5, (int) env('RHINO_ID_FLEETBASE_TOKEN_TTL_MINUTES', 120));
        $token = $dispatchUser->createToken('rhino-id:himbo-express', ['*', 'himbo-express:dispatch'], now()->addMinutes($tokenTtlMinutes))->plainTextToken;

        Log::info('himbo_express.rhino_id_exchange_granted', [
            'ip' => $request->ip(),
            'rhino_id_email' => $rhinoIdEmail,
            'dispatch_user_email' => $dispatchUser->email,
            'token_ttl_minutes' => $tokenTtlMinutes,
        ]);

        return response()->json(
            [
                'provider' => 'rhino-id',
                'token' => $token,
                'identity' => $dispatchUser->email,
                'type' => method_exists($dispatchUser, 'getType') ? $dispatchUser->getType() : $dispatchUser->type,
                'rhino_id_email' => $rhinoIdEmail
            ]
        )->header('Cache-Control', 'no-store');
    }

    private function rhinoIdPermissionsMatrix(Request $request): array
    {
        $allowedEmails = $this->envList('RHINO_ID_ALLOWED_EMAILS');
        $allowedDomains = $this->envList('RHINO_ID_ALLOWED_EMAIL_DOMAINS', '2rhino.com');
        $dispatchEmail = strtolower((string) env('HIMBO_EXPRESS_DISPATCH_USER_EMAIL', env('RHINO_ID_FLEETBASE_USER_EMAIL', 'admin@himbo.express')));
        $dispatchUser = User::where('email', $dispatchEmail)->first();
        $exactAllowlistEnabled = !empty($allowedEmails);
        $exactAllowlistRequired = filter_var(env('RHINO_ID_REQUIRE_EXACT_EMAILS', true), FILTER_VALIDATE_BOOLEAN);
        $allowedOperators = $exactAllowlistEnabled ? $allowedEmails : array_map(
            fn ($domain) => '*@' . $domain,
            $allowedDomains
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'product' => 'HIMBO EXPRESS',
                'entry' => 'RHINO ID Lounge',
                'status' => 'travis_only',
                'rhino_id_base_url' => rtrim((string) env('RHINO_ID_BASE_URL', 'https://id.2rhino.com'), '/'),
                'dispatch_user_email' => $dispatchEmail,
                'local_password_access' => 'disabled_for_dispatch_handoff',
                'allowlist_mode' => $exactAllowlistEnabled ? 'exact_email' : ($exactAllowlistRequired ? 'exact_email_required_no_operators' : 'domain_fallback'),
                'exact_email_required' => $exactAllowlistRequired,
                'token_ttl_minutes' => max(5, (int) env('RHINO_ID_FLEETBASE_TOKEN_TTL_MINUTES', 120)),
                'allowed_operators' => $allowedOperators,
                'requester' => [
                    'email' => (string) data_get($request->user(), 'email', ''),
                    'is_admin' => (bool) data_get($request->user(), 'is_admin', false),
                ],
            ],
            'dispatch_user' => [
                'email' => $dispatchEmail,
                'exists' => (bool) $dispatchUser,
                'status' => $dispatchUser ? (string) $dispatchUser->status : 'missing',
                'type' => $dispatchUser ? (method_exists($dispatchUser, 'getType') ? $dispatchUser->getType() : $dispatchUser->type) : null,
                'is_admin' => $dispatchUser ? (bool) data_get($dispatchUser, 'is_admin', false) : false,
            ],
            'principals' => [
                [
                    'name' => 'Travis',
                    'identity' => 'travis@2rhino.com',
                    'credential' => 'RHINO ID session',
                    'lounge_tile' => 'allowed',
                    'launch' => 'allowed',
                    'exchange' => $this->rhinoIdEmailIsAllowed('travis@2rhino.com') ? 'allowed' : 'blocked',
                    'console' => $dispatchUser ? 'allowed' : 'blocked',
                    'notes' => 'Exact RHINO ID operator for HIMBO EXPRESS dispatch.',
                ],
                [
                    'name' => 'Other RHINO ID users',
                    'identity' => $exactAllowlistEnabled ? 'not in exact allowlist' : 'outside allowed domains',
                    'credential' => 'RHINO ID session',
                    'lounge_tile' => 'blocked',
                    'launch' => 'blocked',
                    'exchange' => 'blocked',
                    'console' => 'blocked',
                    'notes' => 'The dispatch exchange denies operators outside the current allowlist.',
                ],
                [
                    'name' => 'Public or anonymous',
                    'identity' => 'none',
                    'credential' => 'none',
                    'lounge_tile' => 'blocked',
                    'launch' => 'blocked',
                    'exchange' => 'blocked',
                    'console' => 'blocked',
                    'notes' => 'No RHINO ID session means no Fleetbase token can be issued.',
                ],
                [
                    'name' => 'Fleetbase local password',
                    'identity' => 'local login',
                    'credential' => 'password',
                    'lounge_tile' => 'blocked',
                    'launch' => 'blocked',
                    'exchange' => 'blocked',
                    'console' => 'blocked',
                    'notes' => 'HIMBO EXPRESS dispatch is intended to enter through RHINO ID only.',
                ],
            ],
            'capabilities' => [
                [
                    'capability' => 'See lounge tile',
                    'owner' => 'RHINO ID',
                    'enforcement' => 'lounge worlds access policy',
                    'state' => 'travis_only',
                ],
                [
                    'capability' => 'Launch HIMBO EXPRESS',
                    'owner' => 'RHINO ID',
                    'enforcement' => '/launch/himbo-express',
                    'state' => 'travis_only',
                ],
                [
                    'capability' => 'Exchange RHINO ID session',
                    'owner' => 'HIMBO EXPRESS',
                    'enforcement' => 'RHINO_ID_ALLOWED_EMAILS',
                    'state' => $exactAllowlistEnabled ? 'exact_email_allowlist' : ($exactAllowlistRequired ? 'blocked_until_exact_email_allowlist' : 'domain_fallback'),
                ],
                [
                    'capability' => 'Receive Fleetbase dispatch token',
                    'owner' => 'HIMBO EXPRESS',
                    'enforcement' => 'HIMBO_EXPRESS_DISPATCH_USER_EMAIL',
                    'state' => $dispatchUser ? 'mapped' : 'missing_dispatch_user',
                ],
                [
                    'capability' => 'Open dispatch console',
                    'owner' => 'Fleetbase',
                    'enforcement' => 'Fleetbase token session',
                    'state' => $dispatchUser ? 'allowed_after_exchange' : 'blocked',
                ],
                [
                    'capability' => 'View admin permissions matrix',
                    'owner' => 'Fleetbase Admin',
                    'enforcement' => 'sanctum user + is_admin',
                    'state' => 'admin_only',
                ],
                [
                    'capability' => 'Submit courier enrollment',
                    'owner' => 'HIMBO EXPRESS',
                    'enforcement' => 'valid RHINO ID session + contractor acknowledgements',
                    'state' => 'rhino_id_gated_public_intake',
                ],
            ],
        ];
    }

    private function submitCourierEnrollment(Request $request)
    {
        if (!filter_var(env('HIMBO_COURIER_ENROLLMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(
                [
                    'error' => 'courier_enrollment_not_active',
                    'message' => 'HIMBO Express rider activation is not open yet.',
                    'verification_url' => rtrim((string) env('RHINOVERIFY_BASE_URL', 'https://rhinoverify.com'), '/') . '/verified-rhino-id?source=himbo-express',
                ],
                503
            )->header('Cache-Control', 'no-store');
        }

        $sessionToken = trim((string) $request->input('rhino_id_session', ''));
        if ($sessionToken === '') {
            Log::warning('himbo_express.courier_enrollment_missing_session', [
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 180),
            ]);

            return response()->json(['error' => 'missing_rhino_id_session'], 400)
                ->header('Cache-Control', 'no-store');
        }

        $rhinoIdUser = $this->resolveRhinoIdUser($sessionToken, $request, 'courier_enrollment');
        if (!$rhinoIdUser) {
            return response()->json(['error' => 'invalid_rhino_id_session'], 401)
                ->header('Cache-Control', 'no-store');
        }

        if (!$this->rhinoIdUserIsVerified($rhinoIdUser)) {
            Log::warning('himbo_express.courier_enrollment_unverified_rhino_id', [
                'ip' => $request->ip(),
                'rhino_id_email' => strtolower((string) data_get($rhinoIdUser, 'email', '')),
                'identity_verification_status' => (string) data_get($rhinoIdUser, 'identity_verification_status', ''),
                'background_check_status' => (string) data_get($rhinoIdUser, 'background_check_status', ''),
                'age_gate_status' => (string) data_get($rhinoIdUser, 'age_gate_status', ''),
                'age_gate_method' => (string) data_get($rhinoIdUser, 'age_gate_method', ''),
            ]);

            return response()->json(
                [
                    'error' => 'verified_rhino_id_required',
                    'requirements' => [
                        'verified_rhino_id_account',
                        'government_issued_photo_id',
                        'face_scan',
                        'verified_profile_badge',
                        'verified_rhino_id_wallet_pass',
                        'background_check',
                    ],
                    'verification_url' => rtrim((string) env('RHINOVERIFY_BASE_URL', 'https://rhinoverify.com'), '/') . '/verified-rhino-id?source=himbo-express',
                ],
                403
            )->header('Cache-Control', 'no-store');
        }

        $errors = $this->validateCourierEnrollment($request);
        if (!empty($errors)) {
            return response()->json(['error' => 'invalid_courier_enrollment', 'errors' => $errors], 422)
                ->header('Cache-Control', 'no-store');
        }

        $applicationId = 'hxc_' . now()->format('YmdHis') . '_' . bin2hex(random_bytes(4));
        $rhinoIdEmail = strtolower((string) data_get($rhinoIdUser, 'email', ''));
        $enrollment = [
            'application_id' => $applicationId,
            'submitted_at' => now()->toIso8601String(),
            'status' => 'pending_activation_requirements',
            'account_status' => 'pending_activation',
            'activation_requirements' => [
                'background_check' => 'verified_clear',
                'rhino_id_verification' => 'verified_government_photo_id_and_face_scan',
                'provider_costs' => 'rider_paid_at_cost_no_markup',
                'protection_reserve' => 'required_refundable_100_usd_after_approval',
                'liability_insurance' => 'proof_required_before_activation',
                'vehicle' => 'electric_bike_or_e_scooter',
                'age_and_work_authorization' => 'rider_attestation_required',
                'hipaa_privacy_training' => 'required_before_activation',
                'privacy_handling' => 'required',
            ],
            'activation_pricing' => [
                'billing_model' => 'actual_provider_cost_at_cost',
                'activation_markup_usd' => 0,
                'payer' => 'rider',
                'protection_reserve_is_revenue' => false,
            ],
            'protection_reserve_policy' => [
                'amount_usd' => 100,
                'collected_after_approval' => true,
                'refundable' => true,
                'refund_timing' => 'after_account_closure_and_documented_claim_resolution',
                'purpose' => 'backs lost, stolen, or damaged delivery coverage',
                'coverage_usd' => 100,
                'claim_requires_documentation' => true,
                'claim_notice_and_appeal_required' => true,
                'restore_required_after_claim' => true,
            ],
            'rider_benefit' => [
                'entitlement' => 'himbo_express_active_rider',
                'status' => 'pending_activation',
                'scope' => 'himbo_express_line',
                'himbo_cloud_discount_percent' => 10,
                'himbo_cloud_free_shipping' => true,
                'redemption' => 'rhino_id_entitlement_required',
            ],
            'delivery_economics' => [
                'service_area' => 'Wilton Manors',
                'delivery_price_usd' => 10,
                'courier_payout_usd' => 5,
                'himbo_payout_usd' => 5,
                'tips_to_courier_percent' => 100,
            ],
            'rhino_id' => [
                'email' => $rhinoIdEmail,
                'id' => (string) data_get($rhinoIdUser, 'id', ''),
                'session_hash' => hash('sha256', $sessionToken),
                'identity_verification_status' => (string) data_get($rhinoIdUser, 'identity_verification_status', 'verified'),
                'identity_verification_method' => (string) data_get($rhinoIdUser, 'identity_verification_method', 'government_photo_id_face_scan'),
                'background_check_status' => (string) data_get($rhinoIdUser, 'background_check_status', 'clear'),
                'verified_badge' => true,
                'wallet_pass_badge' => (string) data_get($rhinoIdUser, 'wallet_pass_badge', 'Verified RHINO ID'),
            ],
            'compliance_posture' => [
                'intake_collects' => [
                    'contact',
                    'vehicle',
                    'availability',
                    'contractor_acknowledgements',
                ],
                'intake_does_not_collect' => [
                    'government_id_images',
                    'face_images_or_biometric_templates',
                    'social_security_numbers',
                    'background_check_report_details',
                    'patient_or_recipient_information',
                    'medical_details',
                    'delivery_contents',
                ],
                'sensitive_verification_source' => 'RHINO_ID_or_approved_provider',
                'hipaa_privacy_training_required_before_activation' => true,
                'background_check_consent_required_before_screening' => true,
                'legal_review_required_before_public_recruitment' => true,
            ],
            'applicant' => [
                'legal_name' => trim((string) $request->input('legal_name')),
                'business_name' => trim((string) $request->input('business_name', '')),
                'contact_phone' => trim((string) $request->input('contact_phone')),
                'city' => trim((string) $request->input('city', '')),
                'vehicle_type' => trim((string) $request->input('vehicle_type')),
                'availability' => trim((string) $request->input('availability', '')),
                'experience' => trim((string) $request->input('experience', '')),
            ],
            'acknowledgements' => [
                'independent_contractor' => true,
                'not_employee' => true,
                'owns_business' => true,
                'age_and_work_authorization' => true,
                'verified_rhino_id' => true,
                'government_photo_id' => true,
                'face_scan' => true,
                'background_check' => true,
                'hipaa_privacy_training' => true,
                'liability_insurance' => true,
                'protection_reserve_refundable' => true,
                'protection_reserve_restore_after_claim' => true,
                'payout_split' => true,
                'tips' => true,
                'privacy_minimized_intake' => true,
            ],
        ];

        Storage::disk('local')->makeDirectory('himbo-express');
        Storage::disk('local')->append(
            'himbo-express/courier-enrollments.jsonl',
            json_encode($enrollment, JSON_UNESCAPED_SLASHES)
        );

        Log::info('himbo_express.courier_enrollment_submitted', [
            'application_id' => $applicationId,
            'rhino_id_email' => $rhinoIdEmail,
            'vehicle_type' => data_get($enrollment, 'applicant.vehicle_type'),
            'status' => data_get($enrollment, 'status'),
        ]);

        return response()->json(
            [
                'application_id' => $applicationId,
                'status' => 'pending_activation_requirements',
                'account_status' => 'pending_activation',
                'next_steps' => [
                    'background_check_verified',
                    'verified_rhino_id_required',
                    'liability_insurance_proof_required_before_activation',
                    'hipaa_privacy_training_required_before_activation',
                    'refundable_100_usd_protection_reserve_collected_after_approval',
                    'new_routes_paused_if_documented_claim_uses_reserve_until_restored',
                ],
                'delivery_economics' => data_get($enrollment, 'delivery_economics'),
                'activation_pricing' => data_get($enrollment, 'activation_pricing'),
                'protection_reserve_policy' => data_get($enrollment, 'protection_reserve_policy'),
                'rider_benefit' => data_get($enrollment, 'rider_benefit'),
            ],
            202
        )->header('Cache-Control', 'no-store');
    }

    private function resolveRhinoIdUser(string $sessionToken, Request $request, string $context): ?array
    {
        $rhinoIdBaseUrl = rtrim((string) env('RHINO_ID_BASE_URL', 'https://id.2rhino.com'), '/');
        $rhinoIdResponse = Http::acceptJson()
            ->withToken($sessionToken)
            ->timeout(8)
            ->get($rhinoIdBaseUrl . '/me');

        if (!$rhinoIdResponse->successful()) {
            Log::warning('himbo_express.rhino_id_session_invalid', [
                'context' => $context,
                'ip' => $request->ip(),
                'status' => $rhinoIdResponse->status(),
            ]);

            return null;
        }

        $rhinoIdUser = $rhinoIdResponse->json();
        if (!is_array($rhinoIdUser) || strtolower((string) data_get($rhinoIdUser, 'email', '')) === '') {
            Log::warning('himbo_express.rhino_id_session_missing_email', [
                'context' => $context,
                'ip' => $request->ip(),
            ]);

            return null;
        }

        return $rhinoIdUser;
    }

    private function rhinoIdUserIsVerified(array $rhinoIdUser): bool
    {
        return data_get($rhinoIdUser, 'identity_verification_status') === 'verified'
            && filter_var(data_get($rhinoIdUser, 'verified_badge', false), FILTER_VALIDATE_BOOLEAN)
            && data_get($rhinoIdUser, 'identity_verification_method') === 'government_photo_id_face_scan'
            && data_get($rhinoIdUser, 'background_check_status') === 'clear';
    }

    private function validateCourierEnrollment(Request $request): array
    {
        $errors = [];
        foreach (['legal_name', 'contact_phone'] as $field) {
            if (trim((string) $request->input($field, '')) === '') {
                $errors[$field][] = 'required';
            }
        }

        $vehicleType = trim((string) $request->input('vehicle_type', ''));
        if (!in_array($vehicleType, ['electric_bike', 'e_scooter'], true)) {
            $errors['vehicle_type'][] = 'must_be_electric_bike_or_e_scooter';
        }

        $requiredAcknowledgements = [
            'contractor_acknowledged',
            'owns_business_acknowledged',
            'age_authorized_acknowledged',
            'verified_rhino_id_acknowledged',
            'vehicle_acknowledged',
            'insurance_acknowledged',
            'background_check_acknowledged',
            'hipaa_training_acknowledged',
            'protection_reserve_acknowledged',
            'protection_reserve_restore_acknowledged',
            'payout_acknowledged',
            'tips_acknowledged',
            'privacy_acknowledged',
        ];
        foreach ($requiredAcknowledgements as $field) {
            if (!filter_var($request->input($field, false), FILTER_VALIDATE_BOOLEAN)) {
                $errors[$field][] = 'must_be_accepted';
            }
        }

        return $errors;
    }

    private function rhinoIdEmailIsAllowed(string $email): bool
    {
        $allowedEmails = $this->envList('RHINO_ID_ALLOWED_EMAILS');

        if (!empty($allowedEmails)) {
            return in_array(strtolower($email), $allowedEmails, true);
        }

        if (filter_var(env('RHINO_ID_REQUIRE_EXACT_EMAILS', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $allowedDomains = $this->envList('RHINO_ID_ALLOWED_EMAIL_DOMAINS', '2rhino.com');

        if (empty($allowedDomains)) {
            return true;
        }

        foreach ($allowedDomains as $domain) {
            if (str_ends_with($email, '@' . strtolower($domain))) {
                return true;
            }
        }

        return false;
    }

    private function envList(string $key, string $default = ''): array
    {
        return array_values(
            array_filter(
                array_map(
                    fn ($value) => strtolower(trim($value)),
                    explode(',', (string) env($key, $default))
                )
            )
        );
    }
}
