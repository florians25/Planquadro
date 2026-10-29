<?php
namespace Planquadro\Tests\Integration;

use Planquadro\Tests\Support\Client;
use Planquadro\Tests\Support\IntegrationTestCase;

/** White-Label: eigener Name, Farben, Logo auf der Anmeldeseite, Herstellerhinweis abschaltbar. */
final class WhiteLabelTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $this->db()->exec("DELETE FROM app_settings WHERE setting_key='tenant_config'");
        $this->db()->exec("UPDATE app_settings SET setting_value='Planquadro' WHERE setting_key='company_name'");
        parent::tearDown();
    }

    private function cfg(array $branding): void
    {
        $this->db()->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('tenant_config',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([json_encode(['branding' => $branding])]);
        /* Der Name liegt aus Kompatibilitätsgründen in der Alt-Einstellung company_name */
        $this->db()->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('company_name',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([$branding['name'] ?? 'Planquadro']);
    }

    public function testStandardLoginShowsPlanquadro(): void
    {
        $b = (new Client())->get('/')->body;
        $this->assertStringContainsString('Planquadro · Jeder Einsatz an seinem Platz.', $b);
        $this->assertStringContainsString('assets/brand/favicon.svg', $b);
        $this->assertStringNotContainsString('class="logo-img"', $b);
    }

    public function testCompanyNameLogoAndVendorHint(): void
    {
        $this->cfg(['name' => 'Muster Service GmbH', 'primary' => '#0f766e', 'accent' => '#f59e0b', 'logo' => 'logo.png']);
        $b = (new Client())->get('/')->body;
        $this->assertStringContainsString('<title>Muster Service GmbH – Login</title>', $b);
        $this->assertStringContainsString('Muster Service GmbH · mit Planquadro', $b);
        $this->assertStringContainsString('--p:#0f766e', $b);
        $this->assertStringContainsString('class="logo-img"', $b, 'eigenes Logo statt Planquadro-Zeichen');
        $this->cfg(['name' => 'Muster Service GmbH', 'primary' => '#0f766e', 'accent' => '#f59e0b', 'show_vendor' => false]);
        $this->assertStringNotContainsString('mit Planquadro', (new Client())->get('/')->body);
    }

    public function testAdminCanToggleVendorHint(): void
    {
        $c = $this->as('admin');
        $r = $c->postAjax('/organisation', ['action' => 'save_branding', 'name' => 'Nordlicht Technik', 'primary' => '#14213d', 'accent' => '#ff7a1a'])->json();
        $this->assertTrue($r['success']);
        $this->assertStringNotContainsString('mit Planquadro', (new Client())->get('/')->body, 'Checkbox nicht gesetzt = aus');
        $c->postAjax('/organisation', ['action' => 'save_branding', 'name' => 'Nordlicht Technik', 'primary' => '#14213d', 'accent' => '#ff7a1a', 'show_vendor' => '1']);
        $this->assertStringContainsString('Nordlicht Technik · mit Planquadro', (new Client())->get('/')->body);
    }
}
