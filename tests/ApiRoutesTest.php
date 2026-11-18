<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Tests;


/**
 * Class     ApiRoutesTest
 *
 * The JSON API (`log-viewer.route.api`), served from the fixtures' log folder.
 */
class ApiRoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('log-viewer.route.api.enabled', true);
        $app['config']->set('log-viewer.sources', ['all' => ['glob' => 'laravel-*.log', 'format' => 'auto']]);
    }

    public function setUp(): void
    {
        parent::setUp();

        // The API needs a signed-in user by default (web + auth).
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]));
    }

    /** @test */
    public function guests_are_refused(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(route('log-viewer::api.sources'))->assertUnauthorized();
        $this->getJson(route('log-viewer::api.files', ['all']))->assertUnauthorized();
        $this->getJson(route('log-viewer::api.download', ['all', 'laravel-2015-01-01.log']))->assertUnauthorized();
    }

    /** @test */
    public function it_lists_sources_and_levels(): void
    {
        $this->getJson(route('log-viewer::api.sources'))
             ->assertOk()
             ->assertJsonPath('data', ['all'])
             ->assertJsonStructure(['levels' => ['error', 'info']]);
    }

    /** @test */
    public function it_lists_a_sources_files(): void
    {
        $response = $this->getJson(route('log-viewer::api.files', ['all']))->assertOk();

        static::assertContains('laravel-2015-01-01.log', array_column($response->json('data'), 'name'));
    }

    /** @test */
    public function it_shows_a_page_of_entries(): void
    {
        $this->getJson(route('log-viewer::api.show', ['all', 'laravel-2015-01-01.log']).'?level=error&per_page=5')
             ->assertOk()
             ->assertJsonPath('meta.level', 'error')
             ->assertJsonPath('meta.per_page', 5)
             ->assertJsonStructure([
                 'data'   => [['level', 'datetime', 'env', 'message', 'context', 'stack']],
                 'meta'   => ['file', 'size', 'modified', 'streaming', 'current_page', 'total', 'last_page'],
                 'levels' => ['all', 'error'],
             ]);
    }

    /** @test */
    public function it_downloads_a_file(): void
    {
        $this->get(route('log-viewer::api.download', ['all', 'laravel-2015-01-01.log']))
             ->assertOk()
             ->assertDownload('laravel-2015-01-01.log');
    }

    /** @test */
    public function it_returns_404_for_unknown_sources_and_files(): void
    {
        $this->getJson(route('log-viewer::api.files', ['nope']))->assertNotFound();
        $this->getJson(route('log-viewer::api.show', ['all', 'laravel.log']))->assertNotFound(); // outside the glob
        $this->getJson(route('log-viewer::api.show', ['all', '..%2F.env']))->assertNotFound();
    }

    /** @test */
    public function the_classic_routes_still_work_with_the_api_on(): void
    {
        $this->get(route('log-viewer::logs.show', ['2015-01-01']))->assertSuccessful();
    }

    /** @test */
    public function entries_come_newest_first(): void
    {
        $data = $this->getJson(route('log-viewer::api.show', ['all', 'laravel-2015-01-01.log']).'?per_page=500')->json('data');

        $dates = array_column($data, 'datetime');
        $sorted = $dates;
        rsort($sorted);

        static::assertSame($sorted, $dates);
    }
}
