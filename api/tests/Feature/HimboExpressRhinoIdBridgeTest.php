<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HimboExpressRhinoIdBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnv('RHINO_ID_BASE_URL', 'https://id.2rhino.com');
        $this->setEnv('RHINO_ID_ALLOWED_EMAILS', 'travis@2rhino.com');
        $this->setEnv('RHINO_ID_REQUIRE_EXACT_EMAILS', 'true');
    }

    public function test_exchange_requires_session_token(): void
    {
        $this->postJson('/auth/rhino-id/exchange', [])
            ->assertStatus(400)
            ->assertJson(['error' => 'missing_rhino_id_session']);
    }

    public function test_exchange_rejects_invalid_rhino_id_session(): void
    {
        Http::fake([
            'https://id.2rhino.com/me' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        $this->postJson('/auth/rhino-id/exchange', ['rhino_id_session' => 'bad-session'])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_rhino_id_session']);
    }

    public function test_exchange_rejects_non_allowlisted_rhino_id_operator(): void
    {
        Http::fake([
            'https://id.2rhino.com/me' => Http::response(['email' => 'operator@2rhino.com'], 200),
        ]);

        $this->postJson('/auth/rhino-id/exchange', ['rhino_id_session' => 'valid-session'])
            ->assertStatus(403)
            ->assertJson(['error' => 'rhino_id_operator_not_allowed']);
    }

    public function test_permissions_matrix_requires_authenticated_admin(): void
    {
        $this->getJson('/int/v1/auth/rhino-id/permissions-matrix')
            ->assertUnauthorized();
    }

    public function test_courier_enrollment_requires_rhino_id_session(): void
    {
        $this->postJson('/int/v1/couriers/enrollments', [])
            ->assertStatus(400)
            ->assertJson(['error' => 'missing_rhino_id_session']);
    }

    public function test_courier_enrollment_rejects_invalid_rhino_id_session(): void
    {
        Http::fake([
            'https://id.2rhino.com/me' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        $this->postJson('/int/v1/couriers/enrollments', ['rhino_id_session' => 'bad-session'])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_rhino_id_session']);
    }

    public function test_courier_enrollment_requires_contractor_acknowledgements(): void
    {
        Http::fake([
            'https://id.2rhino.com/me' => Http::response($this->verifiedRhinoIdUser(), 200),
        ]);

        $this->postJson('/int/v1/couriers/enrollments', ['rhino_id_session' => 'valid-session'])
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_courier_enrollment'])
            ->assertJsonPath('errors.contractor_acknowledged.0', 'must_be_accepted');
    }

    public function test_courier_enrollment_requires_verified_rhino_id_account(): void
    {
        Http::fake([
            'https://id.2rhino.com/me' => Http::response([
                'id' => 'rid_123',
                'email' => 'courier@example.com',
                'identity_verification_status' => 'unverified',
                'verified_badge' => false,
            ], 200),
        ]);

        $this->postJson('/int/v1/couriers/enrollments', ['rhino_id_session' => 'valid-session'])
            ->assertStatus(403)
            ->assertJson(['error' => 'verified_rhino_id_required'])
            ->assertJsonPath('requirements.0', 'verified_rhino_id_account');
    }

    public function test_courier_enrollment_writes_pending_application(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://id.2rhino.com/me' => Http::response($this->verifiedRhinoIdUser(), 200),
        ]);

        $response = $this->postJson('/int/v1/couriers/enrollments', [
            'rhino_id_session' => 'valid-session',
            'legal_name' => 'Courier Owner',
            'business_name' => 'Courier Owner LLC',
            'contact_phone' => '555-0100',
            'city' => 'Wilton Manors',
            'vehicle_type' => 'electric_bike',
            'availability' => 'Weekends',
            'experience' => 'Local delivery',
            'contractor_acknowledged' => true,
            'owns_business_acknowledged' => true,
            'verified_rhino_id_acknowledged' => true,
            'vehicle_acknowledged' => true,
            'background_check_acknowledged' => true,
            'deposit_acknowledged' => true,
            'deposit_restore_acknowledged' => true,
            'payout_acknowledged' => true,
            'tips_acknowledged' => true,
            'privacy_acknowledged' => true,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'pending_background_check_and_deposit')
            ->assertJsonPath('delivery_economics.delivery_price_usd', 10)
            ->assertJsonPath('delivery_economics.courier_payout_usd', 5)
            ->assertJsonPath('delivery_economics.tips_to_courier_percent', 100);

        Storage::disk('local')->assertExists('himbo-express/courier-enrollments.jsonl');
        $stored = Storage::disk('local')->get('himbo-express/courier-enrollments.jsonl');
        $this->assertStringContainsString('"email":"courier@example.com"', $stored);
        $this->assertStringContainsString('"wallet_pass_badge":"Verified RHINO ID"', $stored);
        $this->assertStringContainsString('"deposit":"required_nonrefundable_100_usd"', $stored);
    }

    private function verifiedRhinoIdUser(): array
    {
        return [
            'id' => 'rid_123',
            'email' => 'courier@example.com',
            'identity_verification_status' => 'verified',
            'identity_verification_method' => 'government_photo_id_face_scan_background_check',
            'verified_badge' => true,
            'wallet_pass_badge' => 'Verified RHINO ID',
        ];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
