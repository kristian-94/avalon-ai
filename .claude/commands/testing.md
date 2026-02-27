# Testing Guide

## Commands
```bash
php artisan test                                    # all tests
php artisan test --testsuite=Unit                   # unit only
php artisan test --testsuite=Feature                # feature only
php artisan test --filter=GameLoopTest              # specific class
php artisan test --filter=testMethodName            # specific method

./vendor/bin/pint                                   # fix code style
./vendor/bin/pint --test                            # check without fixing
```

## Test Files

**Unit:**
- `GameLoopTest.php` — core game logic and phase transitions
- `GameLoopImprovementsTest.php` — edge cases and enhancements
- `GameLoopTimeoutTest.php` — phase timeout handling
- `OpenAIServiceImprovementsTest.php` — AI API integration

**Feature:**
- `GameStateTransitionTest.php` — full game flow
- `GameRulesComplianceTest.php` — rules validation
- `TeamVotingMechanicsTest.php` — voting system

## Notes
- AI integration is mocked in all tests for deterministic scenarios
- Never skip tests, remove assertions, or take shortcuts
- Tests use `QUEUE_CONNECTION=sync` (set in `phpunit.xml`)
