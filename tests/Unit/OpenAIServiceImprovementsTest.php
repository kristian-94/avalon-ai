<?php

namespace Tests\Unit;

use App\Services\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class OpenAIServiceImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected OpenAIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpenAIService();
    }

    /**
     * Make private/protected methods accessible for testing
     */
    private function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    public function test_extract_current_phase_from_messages()
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are playing Avalon'],
            ['role' => 'system', 'content' => '=== CURRENT GAME STATE ===\nPhase: team_voting\nMissions: 1 successful'],
            ['role' => 'user', 'content' => 'Alice: I think we should trust Bob'],
        ];

        $phase = $this->invokeMethod($this->service, 'extractCurrentPhase', [$messages]);
        
        $this->assertEquals('team_voting', $phase);
    }

    public function test_extract_current_phase_returns_unknown_when_not_found()
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are playing Avalon'],
            ['role' => 'user', 'content' => 'Alice: Hello everyone'],
        ];

        $phase = $this->invokeMethod($this->service, 'extractCurrentPhase', [$messages]);
        
        $this->assertEquals('unknown', $phase);
    }

    public function test_get_phase_specific_parameters_for_team_proposal_non_leader()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['team_proposal']);

        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('message', $params['properties']);
        $this->assertArrayHasKey('reasoning', $params['properties']);

        // Non-leader should NOT have team_proposal field — they can only chat
        $this->assertArrayNotHasKey('team_proposal', $params['properties']);
    }

    public function test_get_phase_specific_parameters_for_team_proposal_leader()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['team_proposal_leader']);

        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('team_proposal', $params['properties']);

        // Leader MUST propose a team
        $this->assertContains('team_proposal', $params['required']);
        $this->assertStringContainsString('reasoning', $params['properties']['team_proposal']['description']);
    }

    public function test_get_phase_specific_parameters_for_team_voting()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['team_voting']);
        
        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('vote', $params['properties']);
        
        // Vote should be required in voting phase
        $this->assertContains('vote', $params['required']);
        
        // Should not have team proposal option
        $this->assertArrayNotHasKey('team_proposal', $params['properties']);
    }

    public function test_get_phase_specific_parameters_for_mission_observer()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['mission']);

        $this->assertArrayHasKey('properties', $params);

        // Observer should NOT have mission_action field — they can only chat
        $this->assertArrayNotHasKey('mission_action', $params['properties']);
    }

    public function test_get_phase_specific_parameters_for_mission_on_team()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['mission_on_team']);

        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('mission_action', $params['properties']);

        // Team member MUST submit mission action
        $this->assertContains('mission_action', $params['required']);
        $this->assertStringContainsString('reasoning', $params['properties']['mission_action']['description']);
    }

    public function test_get_phase_specific_parameters_for_assassination_non_assassin()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['assassination']);

        $this->assertArrayHasKey('properties', $params);

        // Non-assassin should NOT have assassination_target field
        $this->assertArrayNotHasKey('assassination_target', $params['properties']);
    }

    public function test_get_phase_specific_parameters_for_assassination_assassin()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['assassination_assassin']);

        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('assassination_target', $params['properties']);

        // Assassin MUST choose a target
        $this->assertContains('assassination_target', $params['required']);
        $this->assertStringContainsString('reasoning', $params['properties']['assassination_target']['description']);
    }

    public function test_get_phase_specific_parameters_for_unknown_phase()
    {
        $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', ['unknown']);
        
        // Should only have base properties
        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('message', $params['properties']);
        $this->assertArrayHasKey('reasoning', $params['properties']);
        
        // Should not have any phase-specific properties
        $this->assertArrayNotHasKey('vote', $params['properties']);
        $this->assertArrayNotHasKey('team_proposal', $params['properties']);
        $this->assertArrayNotHasKey('mission_action', $params['properties']);
        $this->assertArrayNotHasKey('assassination_target', $params['properties']);
    }

    public function test_chat_response_uses_phase_specific_parameters()
    {
        // Mock the HTTP response
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'function_call' => [
                            'name' => 'game_response',
                            'arguments' => json_encode([
                                'message' => 'I vote to approve this team',
                                'reasoning' => 'The team looks balanced',
                                'vote' => true
                            ])
                        ]
                    ]
                ]]
            ], 200)
        ]);

        $messages = [
            ['role' => 'system', 'content' => 'You are playing Avalon'],
            ['role' => 'system', 'content' => '=== CURRENT GAME STATE ===\nPhase: team_voting\nProposed Team: Alice, Bob'],
        ];

        $response = $this->service->getChatResponse($messages);

        // Response should include the vote
        $this->assertArrayHasKey('vote', $response);
        $this->assertTrue($response['vote']);
        $this->assertEquals('I vote to approve this team', $response['message']);
    }

    public function test_fallback_response_format()
    {
        // Mock a failed HTTP response
        Http::fake([
            'api.openai.com/*' => Http::response([], 500)
        ]);

        $messages = [
            ['role' => 'system', 'content' => 'Test message'],
        ];

        $response = $this->service->getChatResponse($messages);

        // Should return fallback response
        $this->assertEquals('', $response['message']);
        $this->assertStringContainsString('Encountered an issue', $response['reasoning']);
        $this->assertNull($response['vote']);
        $this->assertNull($response['mission_action']);
    }

    public function test_phase_specific_descriptions_are_helpful()
    {
        // Test that descriptions provide clear guidance for each phase
        $phases = ['team_proposal', 'team_proposal_leader', 'team_voting', 'mission', 'mission_on_team', 'assassination', 'assassination_assassin'];

        foreach ($phases as $phase) {
            $params = $this->invokeMethod($this->service, 'getPhaseSpecificParameters', [$phase]);

            // Message should always provide in-character dialogue guidance
            $this->assertStringContainsString('in-character',
                $params['properties']['message']['description'],
                "Phase {$phase} missing in-character guidance in message description");

            // Reasoning should be about private thoughts
            $this->assertStringContainsString('Private',
                $params['properties']['reasoning']['description'],
                "Phase {$phase} missing private reasoning description");
        }
    }
}