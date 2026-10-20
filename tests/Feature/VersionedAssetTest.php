<?php

namespace Tests\Feature;

use App\Support\VersionedAsset;
use Tests\TestCase;

class VersionedAssetTest extends TestCase
{
    public function test_html_uses_versioned_assets_and_cannot_be_stored_by_caches(): void
    {
        $response = $this->get('/');
        $response->assertOk()
            ->assertSee(VersionedAsset::url('css/app.css'), false)
            ->assertSee(VersionedAsset::url('js/nav.js'), false)
            ->assertSee(VersionedAsset::url('js/release-notice.js'), false)
            ->assertSee('Nova versão do ManaLeague disponível.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_core_release_header_matches_the_version_rendered_in_html(): void
    {
        $response = $this->get('/');
        $version = $response->headers->get('X-ManaLeague-Assets');
        $this->assertNotEmpty($version);
        $response->assertSee('data-release="'.$version.'"', false);
        $this->head('/')->assertHeader('X-ManaLeague-Assets', $version);
    }

    public function test_asset_version_changes_with_content_even_when_timestamp_is_preserved(): void
    {
        $root = storage_path('framework/testing/assets-'.bin2hex(random_bytes(6)));
        mkdir($root.'/css', 0777, true);
        $original = public_path();
        $this->app->usePublicPath($root);
        try {
            file_put_contents($root.'/css/test.css', 'body{color:red}');
            touch($root.'/css/test.css', 1700000000);
            $first = VersionedAsset::url('css/test.css');
            $this->assertMatchesRegularExpression('/test\.css\?v=[a-f0-9]{16}$/', $first);
            $this->assertSame($first, VersionedAsset::url('css/test.css'));
            file_put_contents($root.'/css/test.css', 'body{color:tan}');
            touch($root.'/css/test.css', 1700000000);
            $this->assertNotSame($first, VersionedAsset::url('css/test.css'));
            $this->assertSame(asset('js/missing.js'), VersionedAsset::url('js/missing.js'));
        } finally {
            $this->app->usePublicPath($original);
            unlink($root.'/css/test.css');
            rmdir($root.'/css');
            rmdir($root);
        }
    }
}
