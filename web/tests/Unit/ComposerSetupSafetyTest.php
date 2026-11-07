<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ComposerSetupSafetyTest extends TestCase
{
    public function test_setup_and_automatic_hooks_cannot_generate_keys_or_provision_a_database(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($composer['scripts'] as $name => $commands) {
            if ($name !== 'setup' && ! str_starts_with($name, 'post-') && ! str_starts_with($name, 'pre-')) {
                continue;
            }
            foreach ((array) $commands as $command) {
                $this->assertDoesNotMatchRegularExpression('/key:generate|\bmigrate\b|\bdb:(seed|wipe)\b|database\.sqlite|APP_KEY\s*=/i', $command, $name);
            }
        }
        $this->assertContains('composer install', $composer['scripts']['setup']);
        $this->assertContains('npm run build', $composer['scripts']['setup']);
    }
}
